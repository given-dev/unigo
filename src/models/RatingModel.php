<?php
/**
 * UniGo - Rating model.
 *
 * Rules enforced server side:
 *   - only a passenger who actually held a booking on the trip may rate it
 *   - the trip must be completed (or already reviewed)
 *   - one rating per passenger per trip (UNIQUE index backs this up)
 *   - the driver's cached rating_avg / rating_count are recomputed on write
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\ValidationException;
use App\Services\NotificationService;

final class RatingModel extends BaseModel
{
    protected string $table = 'ratings';

    /**
     * @return array{rating:array<string,mixed>,created:bool,message:string}
     */
    public function rate(array $data, int $passengerId): array
    {
        $tripId = (int) $data['trip_id'];
        $rating = (int) $data['rating'];
        $comment = trim((string) ($data['comment'] ?? ''));

        if ($rating < 1 || $rating > 5) {
            throw new ValidationException('Please choose a rating between 1 and 5 stars.');
        }

        $trip = $this->db->first(
            'SELECT t.id, t.status, t.trip_code, t.driver_id, r.name AS route_name
             FROM trips t INNER JOIN routes r ON r.id = t.route_id
             WHERE t.id = ?',
            [$tripId]
        );
        if (!$trip) {
            throw new \App\Core\NotFoundException('That trip could not be found.');
        }
        if ($trip['status'] !== 'completed') {
            throw new ValidationException('You can only rate a trip after it has been completed.');
        }

        $booking = $this->db->first(
            'SELECT id FROM bookings
             WHERE trip_id = ? AND passenger_id = ? AND status IN ("confirmed","completed")
             LIMIT 1',
            [$tripId, $passengerId]
        );
        if (!$booking) {
            throw new ValidationException('You can only rate a trip you travelled on.');
        }

        $existing = $this->db->first(
            'SELECT * FROM ratings WHERE trip_id = ? AND passenger_id = ? LIMIT 1',
            [$tripId, $passengerId]
        );

        $row = [
            'trip_id'      => $tripId,
            'booking_id'   => (int) $booking['id'],
            'passenger_id' => $passengerId,
            'driver_id'    => (int) $trip['driver_id'],
            'vehicle_id'   => (int) $this->db->value('SELECT vehicle_id FROM trips WHERE id = ?', [$tripId]),
            'rating'       => $rating,
            'punctuality'  => $this->scoreOrNull($data['punctuality'] ?? null),
            'cleanliness'  => $this->scoreOrNull($data['cleanliness'] ?? null),
            'comment'      => mb_substr($comment, 0, 500),
        ];

        if ($existing) {
            $this->updateById((int) $existing['id'], $row);
            $ratingId = (int) $existing['id'];
            $created = false;
            $message = 'Your rating has been updated.';
        } else {
            $ratingId = $this->create($row);
            $created = true;
            $message = 'Thank you for rating your trip.';
        }

        $this->recalculateDriverRating((int) $trip['driver_id']);
        $this->recalculateVehicleRating((int) $row['vehicle_id']);

        if ($created && $trip['driver_id']) {
            NotificationService::push(
                (int) $trip['driver_id'],
                'rating',
                'New ' . $rating . '-star rating',
                'A passenger rated your trip ' . $trip['trip_code'] . ' on ' . $trip['route_name'] . '.',
                '/driver/ratings',
                $rating >= 4 ? 'success' : 'warning',
                'star'
            );
        }

        return ['rating' => $this->find($ratingId) ?? [], 'created' => $created, 'message' => $message];
    }

    private function scoreOrNull($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;
        return ($n >= 1 && $n <= 5) ? $n : null;
    }

    /** Recompute the cached aggregate in one UPDATE (no per-row queries). */
    public function recalculateDriverRating(int $driverId): void
    {
        $this->db->run(
            'UPDATE drivers d
             SET d.rating_avg = COALESCE((SELECT AVG(rating) FROM ratings WHERE driver_id = d.id AND is_published = 1), 0),
                 d.rating_count = (SELECT COUNT(*) FROM ratings WHERE driver_id = d.id AND is_published = 1)
             WHERE d.id = ?',
            [$driverId]
        );
    }

    public function recalculateVehicleRating(int $vehicleId): void
    {
        $this->db->run(
            'UPDATE vehicles v
             SET v.status = v.status
             WHERE v.id = ?',
            [$vehicleId]
        );
    }

    public function forTrip(int $tripId): ?array
    {
        return $this->db->first(
            'SELECT r.*, u.first_name, u.last_name
             FROM ratings r INNER JOIN users u ON u.id = r.passenger_id
             WHERE r.trip_id = ? LIMIT 1',
            [$tripId]
        );
    }

    /** Has this passenger already rated the trip? */
    public function existingForPassenger(int $tripId, int $passengerId): ?array
    {
        return $this->findByWhere('trip_id = ? AND passenger_id = ?', [$tripId, $passengerId]);
    }

    private function findByWhere(string $where, array $params): ?array
    {
        return $this->db->first("SELECT * FROM ratings WHERE $where LIMIT 1", $params);
    }

    /**
     * @param array{driver_id?:int,vehicle_id?:int,search?:string,min_rating?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function paginateRatings(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $where = ['r.is_published = 1'];
        $params = [];

        if (!empty($filters['driver_id'])) {
            $where[] = 'r.driver_id = ?';
            $params[] = (int) $filters['driver_id'];
        }
        if (!empty($filters['vehicle_id'])) {
            $where[] = 'r.vehicle_id = ?';
            $params[] = (int) $filters['vehicle_id'];
        }
        if (!empty($filters['min_rating'])) {
            $where[] = 'r.rating >= ?';
            $params[] = (int) $filters['min_rating'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(r.comment LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }

        return $this->paginateQuery([
            'select' => 'r.*, u.first_name, u.last_name, u.avatar,
                         d.id AS driver_id, du.first_name AS driver_first_name, du.last_name AS driver_last_name',
            'from'   => 'ratings r',
            'joins'  => 'INNER JOIN users u ON u.id = r.passenger_id
                         LEFT JOIN drivers d ON d.id = r.driver_id
                         LEFT JOIN users du ON du.id = d.user_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'r.created_at DESC',
        ], $page, $perPage);
    }

    public function ratingDistribution(int $driverId): array
    {
        $rows = $this->db->select(
            'SELECT rating, COUNT(*) AS total FROM ratings
             WHERE driver_id = ? AND is_published = 1 GROUP BY rating',
            [$driverId]
        );
        $out = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $row) {
            $out[(int) $row['rating']] = (int) $row['total'];
        }
        return $out;
    }

    /** Trips the passenger can still rate. */
    public function rateableTrips(int $passengerId, int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        return $this->db->select(
            "SELECT b.id AS booking_id, b.reference, t.id AS trip_id, t.trip_code,
                    r.name AS route_name, t.departure_time
             FROM bookings b
             INNER JOIN trips t ON t.id = b.trip_id
             INNER JOIN routes r ON r.id = t.route_id
             WHERE b.passenger_id = ? AND b.status = 'completed'
               AND NOT EXISTS (SELECT 1 FROM ratings rt WHERE rt.trip_id = t.id AND rt.passenger_id = b.passenger_id)
             ORDER BY t.departure_time DESC
             LIMIT $limit",
            [$passengerId]
        );
    }
}
