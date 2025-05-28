<?php

namespace App\Controllers;

use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\Carpool;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use PDO;

class RideController
{
    private $db;
    private $view;
    private Ride $rideModel;
    private RideRequest $requestModel;
    private Carpool $carpoolModel;

    public function __construct(ContainerInterface $container)
    {
        $this->db = $container->get('db');
        $this->view = $container->get('view');
        $this->rideModel = new Ride($this->db);
        $this->requestModel = new RideRequest($this->db);
        $this->carpoolModel = new Carpool($this->db);
    }



    // public function requestRide(Request $request, Response $response): Response
    // {
    //     if (session_status() === PHP_SESSION_NONE) session_start();

    //     if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'user') {
    //         return $this->jsonResponse($response, ['error' => 'Unauthorized'], 403);
    //     }

    //     $passengerId = $_SESSION['user']['id'];
    //     $data = json_decode($request->getBody()->getContents(), true);

    //     if (!isset($data['pickup_location'], $data['dropoff_location'], $data['passenger_count'])) {
    //         return $this->jsonResponse($response, ['error' => 'Missing fields'], 400);
    //     }

    //     $passengerCount = (int) $data['passenger_count'];
    //     if ($passengerCount < 1 || $passengerCount > 4) {
    //         return $this->jsonResponse($response, ['error' => 'Passenger count must be between 1 and 4'], 400);
    //     }

    //     try {
    //         $this->requestModel->create([
    //             'passenger_id' => $passengerId,
    //             'pickup_location' => $data['pickup_location'],
    //             'dropoff_location' => $data['dropoff_location'],
    //             'passenger_count' => $passengerCount
    //         ]);

    //         return $this->jsonResponse($response, ['message' => 'Ride request submitted successfully'], 201);
    //     } catch (\PDOException $e) {
    //         return $this->jsonResponse($response, ['error' => 'Database error: ' . $e->getMessage()], 500);
    //     }
    // }

    public function getPassengerRideHistory(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'];

        $stmt = $this->db->prepare("
    SELECT rr.*, c.departure_time, c.pickup_location, c.dropoff_location, c.status AS carpool_status,
        EXISTS (
            SELECT 1 FROM ride_reviews r
            WHERE r.ride_request_id = rr.id AND r.reviewer_id = :user_id
        ) AS review_exists
    FROM ride_requests rr
    JOIN carpools c ON rr.carpool_id = c.id
    WHERE rr.passenger_id = :user_id
    ORDER BY c.departure_time DESC
");
        $stmt->execute(['user_id' => $userId]);
        $rides = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by carpool status
        $grouped = [
            'upcoming' => [],
            'in progress' => [],
            'completed' => [],
            'canceled' => [],
        ];

        foreach ($rides as $ride) {
            $status = $ride['carpool_status'];
            if (isset($grouped[$status])) {
                $grouped[$status][] = $ride;
            }
        }

        return $this->view->render($response, 'rides.twig', [
            'grouped_rides' => $grouped
        ]);
    }

    public function getDriverRideHistory(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'driver') {
            return $this->jsonResponse($response, ['error' => 'Unauthorized'], 401);
        }

        $driverId = $_SESSION['user']['id'];

        try {
            // 1. Fetch Carpools Created by the Driver
            $carpoolsStmt = $this->db->prepare("
            SELECT id, pickup_location, dropoff_location, departure_time,
                   total_seats, occupied_seats, status
            FROM carpools
            WHERE driver_id = :driver_id
              AND status IN ('upcoming', 'in progress', 'completed')
            ORDER BY
              CASE
                WHEN status = 'in progress' THEN 1
                WHEN status = 'upcoming' AND occupied_seats > 0 THEN 2
                WHEN status = 'upcoming' AND occupied_seats = 0 THEN 3
                WHEN status = 'completed' THEN 4
                ELSE 5
              END ASC,
              departure_time ASC
        ");
            $carpoolsStmt->execute(['driver_id' => $driverId]);
            $carpools = $carpoolsStmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fetch Reviews Left for the Driver
            $reviewsStmt = $this->db->prepare("
            SELECT rr.rating, rr.comment, rr.created_at, u.name AS reviewer_name
            FROM ride_reviews rr
            JOIN users u ON rr.reviewer_id = u.id
            WHERE rr.target_id = :driver_id AND rr.status = 'approved'
            ORDER BY rr.created_at DESC
        ");
            $reviewsStmt->execute(['driver_id' => $driverId]);
            $reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->view->render($response, 'driver-dashboard.twig', [
                'carpools' => $carpools,
                'reviews' => $reviews
            ]);
        } catch (\PDOException $e) {
            return $this->jsonResponse($response, [
                'error' => 'Database error: ' . $e->getMessage()
            ], 500);
        }
    }



    public function acceptRide(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'driver') {
            return $this->jsonResponse($response, ['error' => 'Unauthorized'], 403);
        }

        $driverId = $_SESSION['user']['id'];
        $rideRequestId = $args['id'] ?? null;

        if (!$rideRequestId) {
            return $this->jsonResponse($response, ['error' => 'Missing ride request ID'], 400);
        }

        try {
            $this->db->beginTransaction();

            // Fetch the ride request
            $stmt = $this->db->prepare("SELECT * FROM ride_requests WHERE id = :id AND status = 'pending'");
            $stmt->execute(['id' => $rideRequestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $this->db->rollBack();
                return $this->jsonResponse($response, ['error' => 'Ride request not found or already accepted'], 404);
            }

            // Get vehicle details
            $vehicleStmt = $this->db->prepare("SELECT id, seats FROM vehicles WHERE driver_id = :driver_id");
            $vehicleStmt->execute(['driver_id' => $driverId]);
            $vehicle = $vehicleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) {
                $this->db->rollBack();
                return $this->jsonResponse($response, ['error' => 'No registered vehicle found for this driver'], 400);
            }

            $requestedSeats = (int)$request['passenger_count'];

            // Check or create an active carpool
            $carpoolStmt = $this->db->prepare("SELECT * FROM carpools WHERE driver_id = :driver_id AND status = 'upcoming'");
            $carpoolStmt->execute(['driver_id' => $driverId]);
            $carpool = $carpoolStmt->fetch(PDO::FETCH_ASSOC);

            if (!$carpool) {
                // Create new carpool if none exists
                $createCarpool = $this->db->prepare("INSERT INTO carpools (driver_id, vehicle_id, total_seats, occupied_seats, status, created_at, updated_at) VALUES (:driver_id, :vehicle_id, :total_seats, 0, 'upcoming', NOW(), NOW())");
                $createCarpool->execute([
                    'driver_id' => $driverId,
                    'vehicle_id' => $vehicle['id'],
                    'total_seats' => $vehicle['seats']
                ]);

                $carpoolId = $this->db->lastInsertId();
                $carpool = [
                    'id' => $carpoolId,
                    'occupied_seats' => 0,
                    'total_seats' => $vehicle['seats']
                ];
            }

            // Check if seats are available
            $availableSeats = (int)$carpool['total_seats'] - (int)$carpool['occupied_seats'];

            if ($requestedSeats > $availableSeats) {
                $this->db->rollBack();
                return $this->jsonResponse($response, ['error' => 'Not enough seats available'], 400);
            }

            // Accept the ride request
            $insertRide = $this->db->prepare("
                INSERT INTO rides (
                    passenger_id, driver_id, vehicle_id, pickup_location, dropoff_location, status, ride_request_id
                ) VALUES (
                    :passenger_id, :driver_id, :vehicle_id, :pickup, :dropoff, 'accepted', :ride_request_id
                )
           ");
            $insertRide->execute([
                'passenger_id' => $request['passenger_id'],
                'driver_id' => $driverId,
                'vehicle_id' => $vehicle['id'],
                'pickup' => $request['pickup_location'],
                'dropoff' => $request['dropoff_location'],
                'ride_request_id' => $rideRequestId  
            ]);


            $updateRequest = $this->db->prepare("UPDATE ride_requests SET status = 'accepted', driver_id = :driver_id WHERE id = :id");
            $updateRequest->execute(['driver_id' => $driverId, 'id' => $rideRequestId]);

            $updateCarpool = $this->db->prepare("UPDATE carpools SET occupied_seats = occupied_seats + :taken, updated_at = NOW() WHERE id = :carpool_id");
            $updateCarpool->execute(['taken' => $requestedSeats, 'carpool_id' => $carpool['id']]);

            $this->db->commit();

            return $this->jsonResponse($response, ['message' => 'Ride accepted successfully'], 200);
        } catch (\PDOException $e) {
            $this->db->rollBack();
            return $this->jsonResponse($response, [
                'error' => 'Database error',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    public function completeRide(Request $request, Response $response, array $args): Response
    {
        $rideId = $args['id'];

        try {
            $this->db->beginTransaction();

            // Get the ride details before updating status
            // This join is critical - the ride_requests table holds the passenger_count
            $stmt = $this->db->prepare("
                SELECT r.*, rr.passenger_count 
                FROM rides r
                JOIN ride_requests rr ON r.passenger_id = rr.passenger_id
                WHERE r.id = :ride_id
            ");
            $stmt->execute(['ride_id' => $rideId]);
            $ride = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ride) {
                $this->db->rollBack();
                return $this->jsonResponse($response, ['error' => 'Ride not found'], 404);
            }

            // Step 1: Mark the ride as completed
            $this->rideModel->updateStatus($rideId, 'completed');

            // Step 2: Get carpool ID
            $carpoolId = $this->carpoolModel->getActiveCarpoolId($ride['driver_id']);

            if ($carpoolId !== null) {
                // Step 3: Decrement the seats by the passenger count
                $this->carpoolModel->decrementOccupiedSeats($carpoolId, (int)$ride['passenger_count']);
            }

            $this->db->commit();
            return $this->jsonResponse($response, ['message' => 'Ride completed successfully']);
        } catch (\PDOException $e) {
            $this->db->rollBack();
            return $this->jsonResponse($response, [
                'error' => 'Database error',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function cancelRide(Request $request, Response $response, array $args): Response
    {
        $rideId = (int)$args['id'];
        $userId = $_SESSION['user']['id'];

        // 1. Fetch ride
        $stmt = $this->db->prepare("SELECT * FROM ride_requests WHERE id = ? AND passenger_id = ?");
        $stmt->execute([$rideId, $userId]);
        $ride = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ride || $ride['status'] !== 'pending') {
            return $response
                ->withHeader('Location', '/rides')
                ->withStatus(302);
        }

        // 2. Cancel ride
        $stmt = $this->db->prepare("UPDATE ride_requests SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$rideId]);

        // 3. Refund credits (passenger_count * 5)
        $refund = $ride['passenger_count'] * 5;
        $stmt = $this->db->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
        $stmt->execute([$refund, $userId]);

        // 4. Decrement occupied seats in carpools
        if (!empty($ride['carpool_id'])) {
            $stmt = $this->db->prepare("UPDATE carpools SET occupied_seats = occupied_seats - ? WHERE id = ?");
            $stmt->execute([$ride['passenger_count'], $ride['carpool_id']]);
        }

        return $response
            ->withHeader('Location', '/rides')
            ->withStatus(302);
    }

    public function listAvailableCarpools(Request $request, Response $response): Response
    {
        $stmt = $this->db->prepare("SELECT c.*, u.name AS driver_name, v.energy_type
        FROM carpools c
        JOIN users u ON c.driver_id = u.id
        JOIN vehicles v ON c.vehicle_id = v.id
        WHERE c.status = 'upcoming' AND (c.total_seats - c.occupied_seats) > 0
        ORDER BY c.created_at DESC");
        $stmt->execute();
        $carpools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->view->render($response, 'carpool-list.twig', [
            'carpools' => $carpools
        ]);
    }

    private function jsonResponse(Response $response, array $data, int $statusCode = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withStatus($statusCode);
    }
}
