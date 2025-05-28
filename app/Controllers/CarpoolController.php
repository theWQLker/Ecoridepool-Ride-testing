<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use MongoDB\Client as MongoDBClient;
use PDO;

class CarpoolController
{
    protected $view;
    protected $db;
    protected $mongo;

    public function __construct(ContainerInterface $container)
    {
        $this->view = $container->get('view');
        $this->db = $container->get('db');

        // ✅ Set up the MongoDB client
        $client = new MongoDBClient('mongodb://localhost:27017');
        $this->mongo = $client->ecoridepool->user_preferences;
    }

    /**
     * Display all available carpools
     * Affiche tous les trajets disponibles
     */
    public function listAvailable(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $pickup = $params['pickup'] ?? null;
        $dropoff = $params['dropoff'] ?? null;
        $minSeats = $params['min_seats'] ?? null;

        $sql = "
        SELECT c.*, u.name AS driver_name, v.energy_type
        FROM carpools c
        JOIN users u ON c.driver_id = u.id
        JOIN vehicles v ON c.vehicle_id = v.id
        WHERE c.status = 'upcoming'
          AND (c.total_seats - c.occupied_seats) > 0
    ";

        $conditions = [];
        $values = [];
        $energy = $params['energy'] ?? null;

        if ($pickup) {
            $conditions[] = 'c.pickup_location LIKE ?';
            $values[] = "%$pickup%";
        }

        if ($dropoff) {
            $conditions[] = 'c.dropoff_location LIKE ?';
            $values[] = "%$dropoff%";
        }

        if ($minSeats) {
            $conditions[] = '(c.total_seats - c.occupied_seats) >= ?';
            $values[] = (int)$minSeats;
        }

        if ($energy) {
            $conditions[] = 'v.energy_type = ?';
            $values[] = $energy;
        }

        $eco = $params['eco'] ?? null;
        if ($eco === '1') {
            $conditions[] = "(v.energy_type IN ('electric', 'hybrid'))";
        }


        if (!empty($conditions)) {
            $sql .= ' AND ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY c.departure_time ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        $carpools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->view->render($response, 'carpool-list.twig', [
            'carpools' => $carpools,
            'filters' => [
                'pickup' => $pickup,
                'dropoff' => $dropoff,
                'min_seats' => $minSeats,
                'energy' => $energy,
                'eco' => $eco
            ]
        ]);
    }

    public function viewDetail(Request $request, Response $response, array $args): Response
    {
        $carpoolId = $args['id'];

        // Fetch SQL carpool + driver + vehicle info
        $stmt = $this->db->prepare("
            SELECT c.*, u.name AS driver_name, u.driver_rating, v.make, v.model, v.energy_type
            FROM carpools c
            JOIN users u ON c.driver_id = u.id
            JOIN vehicles v ON c.vehicle_id = v.id
            WHERE c.id = ?
        ");
        $stmt->execute([$carpoolId]);
        $carpool = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$carpool) {
            $response->getBody()->write("Carpool not found.");
            return $response->withStatus(404);
        }

        //Mongo fetch
        $driverId = (int) $carpool['driver_id'];
        $mongoResult = $this->mongo->findOne(['user_id' => $driverId]);

        $preferences = null;
        if ($mongoResult && isset($mongoResult['preferences'])) {
            $preferences = json_decode(json_encode($mongoResult['preferences']), true);
        }

        return $this->view->render($response, 'carpool-detail.twig', [
            'carpool' => $carpool,
            'preferences' => $preferences
        ]);
    }

    public function joinCarpool(Request $request, Response $response, array $args): Response
    {
        $carpoolId = (int) $args['id'];
        $userId = $_SESSION['user']['id'] ?? null;
        $data = $request->getParsedBody();
        $requestedSeats = max(1, (int) $data['passenger_count']);
        $costPerSeat = 5;
        $totalCost = $requestedSeats * $costPerSeat;

        // 1. Get carpool base
        $stmt = $this->db->prepare("SELECT * FROM carpools WHERE id = ?");
        $stmt->execute([$carpoolId]);
        $carpool = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$carpool) {
            $response->getBody()->write("Carpool not found.");
            return $response->withStatus(404);
        }

        // 2. Check seat availability
        $availableSeats = $carpool['total_seats'] - $carpool['occupied_seats'];
        if ($requestedSeats > $availableSeats) {
            return $this->reloadCarpoolWithMessage($response, $carpoolId, "Not enough available seats. Only $availableSeats left.");
        }

        // 3. Check user credits
        $stmt = $this->db->prepare("SELECT credits FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['credits'] < $totalCost) {
            return $this->reloadCarpoolWithMessage($response, $carpoolId, "You need $totalCost credits to join this ride. You currently have " . ($user['credits'] ?? 0) . ".");
        }

        // 4. Prevent duplicate join
        $stmt = $this->db->prepare("SELECT id FROM ride_requests WHERE passenger_id = ? AND carpool_id = ?");
        $stmt->execute([$userId, $carpoolId]);
        if ($stmt->fetch()) {
            return $this->reloadCarpoolWithMessage($response, $carpoolId, "You have already joined this ride.");
        }

        // 5. Insert ride request
        $stmt = $this->db->prepare("
            INSERT INTO ride_requests (passenger_id, driver_id, carpool_id, pickup_location, dropoff_location, passenger_count, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'accepted', NOW())
        ");
        $stmt->execute([
            $userId,
            $carpool['driver_id'],
            $carpool['id'],
            $carpool['pickup_location'],
            $carpool['dropoff_location'],
            $requestedSeats
        ]);

        // 6. Update carpool seat count
        $stmt = $this->db->prepare("UPDATE carpools SET occupied_seats = occupied_seats + ? WHERE id = ?");
        $stmt->execute([$requestedSeats, $carpoolId]);

        // 7. Deduct credits
        $stmt = $this->db->prepare("UPDATE users SET credits = credits - ? WHERE id = ?");
        $stmt->execute([$totalCost, $userId]);

        // 8. Final reload of full carpool info with success message
        return $this->reloadCarpoolWithMessage($response, $carpoolId, "Successfully joined this carpool. $totalCost credits have been deducted.");
    }

    public function startCarpool(Request $request, Response $response, array $args): Response
    {
        $carpoolId = $args['id'];

        $stmt = $this->db->prepare("SELECT * FROM carpools WHERE id = ?");
        $stmt->execute([$carpoolId]);
        $carpool = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$carpool) {
            $response->getBody()->write("Carpool not found.");
            return $response->withStatus(404);
        }

        if ((int)$carpool['occupied_seats'] === 0) {
            $response->getBody()->write("Cannot start ride with 0 passengers.");
            return $response->withStatus(400);
        }

        $update = $this->db->prepare("UPDATE carpools SET status = 'in progress' WHERE id = ?");
        $update->execute([$carpoolId]);

        return $response
            ->withHeader('Location', '/driver/dashboard')
            ->withStatus(302);
    }


    public function completeCarpool(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $driverId = $_SESSION['user']['id'] ?? null;
        $carpoolId = (int) $args['id'];

        try {
            $stmt = $this->db->prepare("SELECT * FROM carpools WHERE id = :id AND driver_id = :driver_id");
            $stmt->execute(['id' => $carpoolId, 'driver_id' => $driverId]);
            $carpool = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$carpool || $carpool['status'] !== 'in progress') {
                return $response->withStatus(403);
            }

            $this->db->beginTransaction();

            // 1. Mark carpool as completed
            $this->db->prepare("UPDATE carpools SET status = 'completed' WHERE id = :id")
                ->execute(['id' => $carpoolId]);

            // 2. Update all linked ride_requests to 'completed'
            $this->db->prepare("UPDATE ride_requests SET status = 'completed' WHERE carpool_id = :id AND status = 'accepted'")
                ->execute(['id' => $carpoolId]);

            // 3. Credit passengers +10
            $getPassengers = $this->db->prepare("SELECT passenger_id FROM ride_requests WHERE carpool_id = :id AND status = 'completed'");
            $getPassengers->execute(['id' => $carpoolId]);
            $passengers = $getPassengers->fetchAll(PDO::FETCH_ASSOC);

            foreach ($passengers as $passenger) {
                $this->db->prepare("UPDATE users SET credits = credits + 10 WHERE id = :id")
                    ->execute(['id' => $passenger['passenger_id']]);
            }

            $this->db->commit();
            return $response->withHeader('Location', '/driver/dashboard')->withStatus(302);
        } catch (\PDOException $e) {
            $this->db->rollBack();
            $response->getBody()->write(json_encode([
                'error' => 'Database error',
                'details' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }




    /**
     * Show form to offer a new carpool (driver)
     * Affiche le formulaire pour proposer un nouveau covoiturage (conducteur)
     */
    public function createForm(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? null;

        $stmt = $this->db->prepare("SELECT * FROM vehicles WHERE driver_id = ?");
        $stmt->execute([$userId]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->view->render($response, 'carpool-create.twig', [
            'vehicles' => $vehicles
        ]);
    }

    /**
     * Save a new carpool offered by the driver
     * Enregistre un nouveau covoiturage proposé par le conducteur
     */
    public function storeCarpool(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $userId = $_SESSION['user']['id'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO carpools (driver_id, vehicle_id, pickup_location, dropoff_location, departure_time, total_seats, occupied_seats, status)
            VALUES (?, ?, ?, ?, ?, ?, 0, 'upcoming')
        ");
        $stmt->execute([
            $userId,
            $data['vehicle_id'],
            $data['pickup_location'],
            $data['dropoff_location'],
            $data['departure_time'],
            $data['total_seats']
        ]);

        return $response
            ->withHeader('Location', '/carpools')
            ->withStatus(302);
    }
    private function reloadCarpoolWithMessage(Response $response, int $carpoolId, string $joinMessage): Response
    {
        // Re-fetch full carpool info
        $stmt = $this->db->prepare("
        SELECT c.*, u.name AS driver_name, u.driver_rating, v.make, v.model, v.energy_type
        FROM carpools c
        JOIN users u ON c.driver_id = u.id
        JOIN vehicles v ON c.vehicle_id = v.id
        WHERE c.id = ?
    ");
        $stmt->execute([$carpoolId]);
        $carpool = $stmt->fetch(PDO::FETCH_ASSOC);

        // Mongo preferences
        $preferences = null;
        if ($carpool && isset($carpool['driver_id'])) {
            $mongoResult = $this->mongo->findOne(['user_id' => (int)$carpool['driver_id']]);
            if ($mongoResult && isset($mongoResult['preferences'])) {
                $preferences = json_decode(json_encode($mongoResult['preferences']), true);
            }
        }

        return $this->view->render($response, 'carpool-detail.twig', [
            'carpool' => $carpool,
            'preferences' => $preferences,
            'join_message' => $joinMessage
        ]);
    }
}
