<?php

namespace App\Models;

use PDO;

class Carpool
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Decrease the number of occupied seats for a given carpool.
     */
    public function decrementOccupiedSeats(int $carpoolId, int $seatsToFree): void
    {
        $stmt = $this->db->prepare("UPDATE carpools SET occupied_seats = GREATEST(0, occupied_seats - :seats), updated_at = NOW() WHERE id = :id");
        $stmt->execute(['seats' => $seatsToFree, 'id' => $carpoolId]);
        
        // After decrementing seats, check if we should mark the carpool as completed
        $this->checkAndMarkAsCompleted($carpoolId);
    }

    /**
     * Check if carpool should be marked as completed and update if needed.
     * This centralizes the completion logic in one place.
     */
    private function checkAndMarkAsCompleted(int $carpoolId): void
    {
        // Get driver_id for this carpool
        $driverStmt = $this->db->prepare("SELECT driver_id, occupied_seats FROM carpools WHERE id = :id");
        $driverStmt->execute(['id' => $carpoolId]);
        $carpoolData = $driverStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$carpoolData) return;
        
        $driverId = $carpoolData['driver_id'];
        $occupiedSeats = (int)$carpoolData['occupied_seats'];
        
        // Check if all rides are completed or cancelled
        $checkStmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM rides 
            WHERE driver_id = :driver_id 
            AND status NOT IN ('completed', 'cancelled')
        ");
        $checkStmt->execute(['driver_id' => $driverId]);
        $remaining = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // If no active rides and no occupied seats, mark carpool as completed
        if ((int)$remaining['count'] === 0 && $occupiedSeats === 0) {
            $updateStmt = $this->db->prepare("UPDATE carpools SET status = 'completed', updated_at = NOW() WHERE id = :id");
            $updateStmt->execute(['id' => $carpoolId]);
        }
    }

    /**
     * Mark carpool as completed if all related rides are completed.
     */
    public function markAsCompletedIfEligible(int $carpoolId, int $driverId): void
    {
        // We're now delegating this logic to the centralized checkAndMarkAsCompleted method
        $this->checkAndMarkAsCompleted($carpoolId);
    }

    /**
     * Get the active carpool ID for the driver.
     */
    public function getActiveCarpoolId(int $driverId): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM carpools WHERE driver_id = :driver_id AND status = 'upcoming' LIMIT 1");
        $stmt->execute(['driver_id' => $driverId]);
        $carpool = $stmt->fetch(PDO::FETCH_ASSOC);
        return $carpool ? (int)$carpool['id'] : null;
    }

    /**
     * Update carpool status when a ride status is updated.
     */
    public function updateCarpoolStatusByRide(int $rideId): void
    {
        // Get driver and passenger count from ride/ride_requests
        $stmt = $this->db->prepare("
            SELECT r.driver_id, rr.passenger_count 
            FROM rides r 
            JOIN ride_requests rr ON r.passenger_id = rr.passenger_id 
            WHERE r.id = :ride_id
        ");
        $stmt->execute(['ride_id' => $rideId]);
        $rideInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rideInfo) return;

        $driverId = $rideInfo['driver_id'];
        $carpoolId = $this->getActiveCarpoolId($driverId);

        if ($carpoolId !== null) {
            // Decrease occupied_seats by passenger_count
            $this->decrementOccupiedSeats($carpoolId, (int)$rideInfo['passenger_count']);
            
            // The checkAndMarkAsCompleted call is now handled inside decrementOccupiedSeats
        }
    }

    /**
     * Free up seats in the carpool when a ride is cancelled.
     */
    public function decrementSeatsByRide(int $rideId): void
    {
        // Get passenger count and driver_id
        $stmt = $this->db->prepare("
            SELECT r.driver_id, rr.passenger_count 
            FROM rides r
            JOIN ride_requests rr ON r.passenger_id = rr.passenger_id
            WHERE r.id = :ride_id
        ");
        $stmt->execute(['ride_id' => $rideId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$info) return;

        $carpoolId = $this->getActiveCarpoolId($info['driver_id']);
        if ($carpoolId !== null) {
            $this->decrementOccupiedSeats($carpoolId, (int)$info['passenger_count']);
            // Now decrementOccupiedSeats will handle checking if carpool should be completed
        }
    }
}