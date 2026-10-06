<?php
/**
 * UniGo - Trip model.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Paginator;
use App\Services\NotificationService;
use App\Services\ReferenceGenerator;
final class TripModel extends BaseModel
{
    protected string $table = 'trips';

    public const STATUSES = ['scheduled', 'boarding', 'in_transit', 'completed', 'cancelled'];

    /** Columns joined on every trip listing. */
    private const JOINS = 'INNER JOIN routes r ON r.id = t.route_id
                          INNER JOIN vehicles v ON v.id = t.vehicle_id
                          LEFT JOIN operators o ON o.id = t.operator_id
                          LEFT JOIN drivers d ON d.id = t.driver_id
                          LEFT JOIN users du ON du.id = d.user_id';

    private const SELECT = 't.*, r.name AS route_name, r.route_code, r.origin_name, r.destination_name,
                            r.distance_km, r.origin_latitude, r.origin_longitude,
                            r.destination_latitude, r.destination_longitude,
                            v.registration_number, v.vehicle_type, v.make, v.model, v.capacity,
                            (SELECT vi.image_path FROM vehicle_images vi WHERE vi.vehicle_id = v.id
                             ORDER BY vi.sort_order ASC, vi.id ASC LIMIT 1) AS vehicle_image,
                            o.company_name,
                            d.rating_avg, du.first_name AS driver_first_name, du.last_name AS driver_last_name,
                            du.phone AS driver_phone';

    public function findDetailed(int $id): ?array
    {
        $trip = $this->db->first(
            'SELECT ' . self::SELECT . ',
                    (SELECT COUNT(*) FROM bookings b
                      WHERE b.trip_id = t.id AND b.status IN ("pending","confirmed","completed")) AS seats_booked
             FROM trips t ' . self::JOINS . '
             WHERE t.id = ? LIMIT 1',
            [$id]
        );

        if ($trip) {
            // Pickup / drop-off selection and the trip polyline both need the
            // route stops, so they travel with the trip.
            $trip['stops'] = (new RouteModel($this->db))->stops((int) $trip['route_id']);
            $trip['images'] = (new VehicleModel())->images((int) $trip['vehicle_id']);
        }

        return $trip;
    }

    public function seatsBooked(int $tripId): int
    {
        return $this->db->count(
            "SELECT COUNT(*) FROM bookings WHERE trip_id = ? AND status IN ('pending','confirmed','completed')",
            [$tripId]
        );
    }

    public function seatsAvailable(int $tripId): int
    {
        $trip = $this->find($tripId);
        if (!$trip) {
            return 0;
        }
        return max(0, (int) $trip['seats_total'] - $this->seatsBooked($tripId));
    }

    // ------------------------------------------------------------------
    // Driver / operator / passenger views
    // ------------------------------------------------------------------

    /** @return array{items:array<int,array<string,mixed>>,paginator:Paginator} */
    public function forDriver(int $driverId, string $status = '', int $page = 1, int $perPage = 20): array
    {
        $where = ['t.driver_id = ?'];
        $params = [$driverId];
        if ($status !== '' && $status !== 'all') {
            $where[] = 't.status = ?';
            $params[] = $status;
        }
        return $this->paginateQuery([
            'select' => self::SELECT . ', (SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id AND b.status IN ("confirmed","completed")) AS seats_booked',
            'from'   => 'trips t',
            'joins'  => self::JOINS,
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 't.departure_time DESC',
        ], $page, $perPage);
    }

    /** @return array{items:array<int,array<string,mixed>>,paginator:Paginator} */
    public function forOperator(int $operatorId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['t.operator_id = ?'];
        $params = [$operatorId];
        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['route_id'])) {
            $where[] = 't.route_id = ?';
            $params[] = (int) $filters['route_id'];
        }
        if (!empty($filters['date'])) {
            $where[] = 'DATE(t.departure_time) = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(t.trip_code LIKE ? OR r.name LIKE ? OR v.registration_number LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }

        return $this->paginateQuery([
            'select' => self::SELECT . ', (SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id AND b.status IN ("confirmed","completed")) AS seats_booked',
            'from'   => 'trips t',
            'joins'  => self::JOINS,
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 't.departure_time DESC',
        ], $page, $perPage);
    }

    /** Trips a passenger can still act on (upcoming / in progress). */
    public function forPassenger(int $passengerUserId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->select(
            'SELECT t.*, r.name AS route_name, r.origin_name, r.destination_name,
                    v.registration_number, v.vehicle_type, o.company_name,
                    (SELECT vi.image_path FROM vehicle_images vi WHERE vi.vehicle_id = v.id
                     ORDER BY vi.sort_order ASC, vi.id ASC LIMIT 1) AS vehicle_image,
                    b.id AS booking_id, b.reference, b.status AS booking_status, b.seat_number, b.fare AS booking_fare
             FROM bookings b
             INNER JOIN trips t ON t.id = b.trip_id
             INNER JOIN routes r ON r.id = t.route_id
             INNER JOIN vehicles v ON v.id = t.vehicle_id
             LEFT JOIN operators o ON o.id = t.operator_id
             WHERE b.passenger_id = ?
               AND b.status IN ("pending","confirmed")
               AND t.status IN ("scheduled","boarding","in_transit")
               AND (t.status IN ("boarding","in_transit") OR t.departure_time >= NOW())
             ORDER BY t.departure_time ASC
             LIMIT ' . $limit,
            [$passengerUserId]
        );
    }

    /** The driver's current trip (in_transit or boarding). */
    public function currentForDriver(int $driverId): ?array
    {
        return $this->db->first(
            'SELECT ' . self::SELECT . ' FROM trips t ' . self::JOINS . '
             WHERE t.driver_id = ? AND t.status IN ("boarding","in_transit")
             ORDER BY t.departure_time DESC LIMIT 1',
            [$driverId]
        );
    }

    public function nextForDriver(int $driverId): ?array
    {
        return $this->db->first(
            'SELECT ' . self::SELECT . ' FROM trips t ' . self::JOINS . '
             WHERE t.driver_id = ? AND t.status = "scheduled" AND t.departure_time >= NOW()
             ORDER BY t.departure_time ASC LIMIT 1',
            [$driverId]
        );
    }

    // ------------------------------------------------------------------
    // Aggregates (one query per tile, never row-by-row)
    // ------------------------------------------------------------------

    public function countByStatus(): array
    {
        return $this->db->select('SELECT status, COUNT(*) AS total FROM trips GROUP BY status');
    }

    public function countToday(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM trips WHERE DATE(departure_time) = CURDATE()');
    }

    public function activeCount(): int
    {
        return $this->db->count("SELECT COUNT(*) FROM trips WHERE status IN ('boarding','in_transit')");
    }

    /** Daily trip counts for the last N days (Chart.js). */
    public function dailySeries(int $days = 14): array
    {
        return $this->dailySeriesQuery('trips', 'departure_time', [
            'total'     => 'COUNT(*)',
            'completed' => "SUM(status = 'completed')",
            'cancelled' => "SUM(status = 'cancelled')",
        ], $days);
    }

    /** Hour of day distribution - used for the "peak hours" chart. */
    public function hourlyDistribution(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        return $this->db->select(
            "SELECT HOUR(departure_time) AS hour, COUNT(*) AS total
             FROM trips
             WHERE departure_time >= (CURDATE() - INTERVAL ? DAY)
             GROUP BY HOUR(departure_time)
             ORDER BY hour ASC",
            [$days]
        );
    }

    public function completionRate(int $days = 30): float
    {
        $total = $this->db->count(
            'SELECT COUNT(*) FROM trips WHERE departure_time >= (CURDATE() - INTERVAL ? DAY)',
            [$days]
        );
        if ($total === 0) {
            return 0.0;
        }
        $completed = $this->db->count(
            "SELECT COUNT(*) FROM trips WHERE status = 'completed' AND departure_time >= (CURDATE() - INTERVAL ? DAY)",
            [$days]
        );
        return round($completed / $total * 100, 1);
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function createTrip(array $data, ?int $actorId = null): int
    {
        return $this->db->transaction(function () use ($data, $actorId): int {
            $vehicleId = (int) $data['vehicle_id'];
            $vehicle = $this->db->first('SELECT * FROM vehicles WHERE id = ? FOR UPDATE', [$vehicleId]);
            if (!$vehicle) {
                throw new \App\Core\NotFoundException('The selected vehicle was not found.');
            }

            $route = $this->db->first('SELECT * FROM routes WHERE id = ?', [(int) $data['route_id']]);
            if (!$route) {
                throw new \App\Core\NotFoundException('The selected route was not found.');
            }

            if (!empty($data['driver_id'])) {
                $driver = $this->db->first('SELECT id FROM drivers WHERE id = ? FOR UPDATE', [(int) $data['driver_id']]);
                if (!$driver) throw new \App\Core\NotFoundException('Driver not found.');
                if ($this->db->exists("SELECT 1 FROM trips WHERE driver_id = ? AND status IN ('scheduled','boarding','in_transit') AND NOT (arrival_time <= ? OR departure_time >= ?)", [(int) $data['driver_id'], $data['departure_time'], $data['arrival_time']])) {
                    throw new \App\Core\ConflictException('The driver already has a trip in this time window.');
                }
            }

            // Conflict detection: one vehicle cannot be on two trips at once.
            $clash = $this->db->first(
                "SELECT trip_code FROM trips
                 WHERE vehicle_id = ?
                   AND status IN ('scheduled','boarding','in_transit')
                   AND NOT (arrival_time <= ? OR departure_time >= ?)
                 LIMIT 1",
                [$vehicleId, $data['departure_time'], $data['arrival_time']]
            );
            if ($clash) {
                throw new \App\Core\ConflictException('That vehicle already has trip ' . $clash['trip_code'] . ' during this time window.');
            }

            $seatsTotal = (int) ($data['seats_total'] ?? $vehicle['capacity']);
            $arrival = $data['arrival_time'] ?: date('Y-m-d H:i:s', strtotime($data['departure_time'] . ' +' . (int) $route['duration_minutes'] . ' minutes'));

            $tripId = $this->create(array_merge([
                'trip_code'      => ReferenceGenerator::generate('trip'),
                'route_id'       => (int) $data['route_id'],
                'vehicle_id'     => $vehicleId,
                'driver_id'      => $data['driver_id'] ?: null,
                'operator_id'    => $data['operator_id'] ?? $vehicle['operator_id'],
                'departure_time' => $data['departure_time'],
                'arrival_time'   => $arrival,
                'boarding_opens' => $data['boarding_opens'] ?? date('Y-m-d H:i:s', strtotime($data['departure_time'] . ' -15 minutes')),
                'status'         => $data['status'] ?? 'scheduled',
                'seats_total'    => $seatsTotal,
                'fare'           => $data['fare'] ?: $route['base_fare'],
                'currency'       => $data['currency'] ?? (string) Config::get('app.currency', 'UGX'),
                'created_by'     => $actorId,
            ], isset($data['notes']) ? ['notes' => $data['notes']] : []));

            // Notify the driver
            if ($data['driver_id']) {
                NotificationService::tripAssigned((int) $data['driver_id'], $tripId, $route['name'], $data['departure_time']);
            }

            return $tripId;
        });
    }

    /**
     * Validated state machine. Every transition notifies the affected people.
     */
    public function updateStatus(int $tripId, string $status, ?int $actorId = null, string $reason = ''): array
    {
        return $this->db->transaction(fn () => $this->transitionStatus($tripId, $status, $actorId, $reason));
    }

    private function transitionStatus(int $tripId, string $status, ?int $actorId, string $reason): array
    {
        $this->db->first('SELECT id FROM trips WHERE id = ? FOR UPDATE', [$tripId]);
        $trip = $this->findDetailed($tripId);
        if (!$trip) {
            throw new \App\Core\NotFoundException('Trip not found.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \App\Core\ValidationException('Unknown trip status.');
        }

        $allowed = [
            'scheduled'   => ['boarding', 'cancelled'],
            'boarding'    => ['in_transit', 'cancelled', 'scheduled'],
            'in_transit'  => ['completed', 'cancelled'],
            'completed'   => [],
            'cancelled'   => [],
        ];

        if ($trip['status'] === $status) {
            return $trip;
        }
        if (!in_array($status, $allowed[$trip['status']] ?? [], true)) {
            throw new \App\Core\ConflictException(
                'A ' . str_replace('_', ' ', $trip['status']) . ' trip cannot be changed to ' . str_replace('_', ' ', $status) . '.'
            );
        }

        // Serialize fleet changes across trips sharing a vehicle or driver.
        $vehicle = $this->db->first('SELECT status FROM vehicles WHERE id = ? FOR UPDATE', [(int) $trip['vehicle_id']]);
        $driver = $trip['driver_id']
            ? $this->db->first('SELECT d.status, u.status AS user_status FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ? FOR UPDATE', [(int) $trip['driver_id']])
            : null;
        if ($status === 'boarding') {
            $busy = $this->db->exists(
                "SELECT 1 FROM trips WHERE id <> ? AND status IN ('boarding','in_transit') AND (vehicle_id = ? OR driver_id = ?)",
                [$tripId, (int) $trip['vehicle_id'], $trip['driver_id']]
            );
            if ($busy || $vehicle['status'] !== 'active' || ($trip['driver_id'] && (!$driver || $driver['status'] !== 'available' || $driver['user_status'] !== 'active'))) {
                throw new \App\Core\ConflictException('The vehicle or driver is unavailable for boarding.');
            }
        }

        $data = ['status' => $status];
        $now = date('Y-m-d H:i:s');

        if ($status === 'in_transit') {
            $data['actual_departure'] = $now;
            $data['delay_minutes'] = max(0, (int) round((strtotime($now) - strtotime((string) $trip['departure_time'])) / 60));
        }
        if ($status === 'completed') {
            $data['actual_arrival'] = $now;
            $this->db->run("UPDATE bookings SET status = 'completed' WHERE trip_id = ? AND status = 'confirmed'", [$tripId]);
        }
        if ($status === 'cancelled') {
            $data['cancelled_reason'] = $reason !== '' ? $reason : 'Cancelled by ' . ($actorId ? 'an administrator' : 'the operator');
            // Cancel every active booking and notify passengers.
            $bookingIds = $this->db->select(
                "SELECT id, passenger_id FROM bookings WHERE trip_id = ? AND status IN ('pending','confirmed')",
                [$tripId]
            );
            foreach ($bookingIds as $b) {
                $this->db->update('bookings', [
                    'status'        => 'cancelled',
                    'cancelled_at'  => $now,
                    'cancel_reason' => 'Trip cancelled by the operator',
                ], 'id = ?', [(int) $b['id']]);
                \App\Services\PaymentService::refundForBooking((int) $b['id'], $actorId);
                NotificationService::tripCancelled((int) $b['passenger_id'], (string) $trip['trip_code'], (string) $trip['route_name']);
            }
        }
        if ($status === 'boarding') {
            $this->db->update('vehicles', ['status' => 'on_trip'], 'id = ?', [(int) $trip['vehicle_id']]);
            if ($trip['driver_id']) {
                $this->db->update('drivers', ['status' => 'on_trip'], 'id = ?', [(int) $trip['driver_id']]);
            }
            NotificationService::tripBoarding($tripId, (string) $trip['trip_code'], (string) $trip['route_name'], (string) $trip['departure_time']);
        }
        $this->updateById($tripId, $data);

        if (in_array($status, ['scheduled', 'completed', 'cancelled'], true)
            && in_array($trip['status'], ['boarding', 'in_transit'], true)) {
            if (!$this->db->exists("SELECT 1 FROM trips WHERE vehicle_id = ? AND status IN ('boarding','in_transit')", [(int) $trip['vehicle_id']])) {
                $this->db->update('vehicles', ['status' => 'active'], "id = ? AND status = 'on_trip'", [(int) $trip['vehicle_id']]);
            }
            if ($trip['driver_id'] && !$this->db->exists("SELECT 1 FROM trips WHERE driver_id = ? AND status IN ('boarding','in_transit')", [(int) $trip['driver_id']])) {
                $this->db->update('drivers', ['status' => 'available'], "id = ? AND status = 'on_trip'", [(int) $trip['driver_id']]);
            }
        }
        if ($status === 'completed' && $trip['driver_id']) {
            $this->db->update('drivers', [
                'total_trips' => $this->db->count('SELECT COUNT(*) FROM trips WHERE driver_id = ? AND status = "completed"', [(int) $trip['driver_id']]),
            ], 'id = ?', [(int) $trip['driver_id']]);
        }

        \App\Core\ActivityLog::record(
            \App\Core\ActivityLog::TRIP_UPDATED,
            $tripId,
            'trip',
            'Trip ' . $trip['trip_code'] . ' moved to ' . $status
        );

        return $this->findDetailed($tripId) ?? $trip;
    }

    /** @return array{items:array<int,array<string,mixed>>,paginator:Paginator} */
    public function paginateTrips(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['operator_id'])) {
            $where[] = 't.operator_id = ?';
            $params[] = (int) $filters['operator_id'];
        }
        if (!empty($filters['driver_id'])) {
            $where[] = 't.driver_id = ?';
            $params[] = (int) $filters['driver_id'];
        }
        if (!empty($filters['route_id'])) {
            $where[] = 't.route_id = ?';
            $params[] = (int) $filters['route_id'];
        }
        if (!empty($filters['vehicle_id'])) {
            $where[] = 't.vehicle_id = ?';
            $params[] = (int) $filters['vehicle_id'];
        }
        if (!empty($filters['date'])) {
            $where[] = 'DATE(t.departure_time) = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['from_date'])) {
            $where[] = 't.departure_time >= ?';
            $params[] = $filters['from_date'] . ' 00:00:00';
        }
        if (!empty($filters['search'])) {
            $where[] = '(t.trip_code LIKE ? OR r.name LIKE ? OR v.registration_number LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }

        return $this->paginateQuery([
            'select' => self::SELECT . ', (SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id AND b.status IN ("pending","confirmed","completed")) AS seats_booked',
            'from'   => 'trips t',
            'joins'  => self::JOINS,
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 't.departure_time DESC',
        ], $page, $perPage);
    }
}
