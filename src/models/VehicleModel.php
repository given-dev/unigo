<?php
/**
 * UniGo - Vehicle model.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;
use App\Services\NotificationService;

final class VehicleModel extends BaseModel
{
    protected string $table = 'vehicles';

    public const TYPES = ['bus', 'electric_bus', 'taxi', 'boda', 'shared_ride', 'truck', 'boat'];
    public const STATUSES = ['active', 'inactive', 'on_trip', 'maintenance', 'suspended'];

    public function findDetailed(int $id): ?array
    {
        return $this->db->first(
            'SELECT v.*,
                    o.company_name, o.approval_status AS operator_status,
                    du.first_name AS driver_first_name, du.last_name AS driver_last_name,
                    du.email AS driver_email, d.rating_avg AS driver_rating, d.id AS driver_id,
                    r.name AS route_name, r.route_code,
                    vl.latitude AS last_latitude, vl.longitude AS last_longitude,
                    vl.speed AS last_speed, vl.recorded_at AS last_location_at,
                    vl.is_simulated AS last_location_simulated
             FROM vehicles v
             LEFT JOIN operators o ON o.id = v.operator_id
             LEFT JOIN drivers d ON d.id = v.driver_id
             LEFT JOIN users du ON du.id = d.user_id
             LEFT JOIN routes r ON r.id = v.home_route_id
             LEFT JOIN vehicle_locations vl ON vl.id = (
                    SELECT id FROM vehicle_locations WHERE vehicle_id = v.id ORDER BY recorded_at DESC, id DESC LIMIT 1
             )
             WHERE v.id = ? LIMIT 1',
            [$id]
        );
    }

    /** Latest position per vehicle for the map (single query, no N+1). */
    public function positionsForMap(?int $limit = 200): array
    {
        $limit = max(1, min(500, $limit ?? 200));
        return $this->db->select(
            "SELECT v.id, v.registration_number, v.vehicle_type, v.status,
                    vl.latitude, vl.longitude, vl.speed, vl.heading, vl.recorded_at, vl.is_simulated,
                    o.company_name
             FROM vehicles v
             LEFT JOIN operators o ON o.id = v.operator_id
             LEFT JOIN vehicle_locations vl ON vl.id = (
                 SELECT id FROM vehicle_locations WHERE vehicle_id = v.id ORDER BY recorded_at DESC, id DESC LIMIT 1
             )
             WHERE v.status IN ('active','on_trip')
               AND vl.latitude IS NOT NULL
             ORDER BY vl.recorded_at DESC
             LIMIT $limit"
        );
    }

    /**
     * @param array{search?:string,type?:string,status?:string,operator_id?:int,gps?:string,approval?:string} $filters
     */
    public function paginateVehicles(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(v.registration_number LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR o.company_name LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['type'])) {
            $where[] = 'v.vehicle_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'v.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['operator_id'])) {
            $where[] = 'v.operator_id = ?';
            $params[] = (int) $filters['operator_id'];
        }
        if (isset($filters['gps']) && $filters['gps'] !== '') {
            $where[] = 'v.gps_enabled = ?';
            $params[] = (int) $filters['gps'];
        }
        if (!empty($filters['approval'])) {
            // compliance view: documents expiring soon
            if ($filters['approval'] === 'expiring') {
                $where[] = 'v.insurance_expiry IS NOT NULL AND v.insurance_expiry <= (CURDATE() + INTERVAL 30 DAY)';
            } elseif ($filters['approval'] === 'expired') {
                $where[] = 'v.insurance_expiry IS NOT NULL AND v.insurance_expiry < CURDATE()';
            } else {
                $where[] = 'v.inspection_status = ?';
                $params[] = $filters['approval'];
            }
        }

        return $this->paginateQuery([
            'select' => 'v.*, o.company_name,
                         du.first_name AS driver_first_name, du.last_name AS driver_last_name,
                         r.name AS route_name,
                         vl.recorded_at AS last_location_at',
            'from'   => 'vehicles v',
            'joins'  => 'LEFT JOIN operators o ON o.id = v.operator_id
                         LEFT JOIN drivers d ON d.id = v.driver_id
                         LEFT JOIN users du ON du.id = d.user_id
                         LEFT JOIN routes r ON r.id = v.home_route_id
                         LEFT JOIN vehicle_locations vl ON vl.id = (
                             SELECT id FROM vehicle_locations WHERE vehicle_id = v.id ORDER BY recorded_at DESC, id DESC LIMIT 1
                         )',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'v.created_at DESC',
        ], $page, $perPage);
    }

    /** Vehicles an operator is allowed to manage. */
    public function forOperator(int $operatorId, int $limit = 100): array
    {
        return $this->db->select(
            'SELECT * FROM vehicles WHERE operator_id = ? ORDER BY status, registration_number LIMIT ' . max(1, min(500, $limit)),
            [$operatorId]
        );
    }

    /**
     * Ensure the physical seat layout matches capacity.
     * Called when capacity grows so seat maps stay correct.
     */
    public function syncSeats(int $vehicleId, int $capacity): int
    {
        $existing = $this->db->count('SELECT COUNT(*) FROM seats WHERE vehicle_id = ?', [$vehicleId]);
        if ($existing >= $capacity) {
            // Deactivate surplus rows rather than deleting history
            $surplus = $existing - $capacity;
            if ($surplus > 0) {
                $this->db->run(
                    'UPDATE seats SET is_active = 0 WHERE vehicle_id = ? AND is_active = 1
                     ORDER BY (seat_number + 0) DESC LIMIT ' . $surplus,
                    [$vehicleId]
                );
            }
            return 0;
        }

        $rows = [];
        for ($i = $existing + 1; $i <= $capacity; $i++) {
            $rows[] = [
                'vehicle_id'  => $vehicleId,
                'seat_number' => (string) $i,
                'seat_type'   => $i <= 2 ? 'premium' : 'standard',
                'row_number'  => (int) ceil($i / 4),
                'is_active'   => 1,
            ];
        }
        if ($rows) {
            $this->db->run(
                'INSERT INTO seats (vehicle_id, seat_number, seat_type, row_number, is_active) VALUES '
                . implode(',', array_map(static fn (array $r) => '(?,?,?,?,?)', $rows)),
                array_merge(...array_values($rows))
            );
        }
        return count($rows);
    }

    public function setStatus(int $vehicleId, string $status, ?int $actorId = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        $this->updateById($vehicleId, ['status' => $status]);

        // Keep the assigned driver's availability in sync.
        $vehicle = $this->find($vehicleId);
        if ($vehicle && $vehicle['driver_id']) {
            $driverStatus = match ($status) {
                'on_trip'   => 'on_trip',
                'suspended' => 'suspended',
                'active'    => 'available',
                default     => 'off_duty',
            };
            $this->db->update('drivers', ['status' => $driverStatus], 'id = ?', [(int) $vehicle['driver_id']]);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function complianceWatchlist(int $days = 30): array
    {
        return $this->db->select(
            "SELECT v.id, v.registration_number, v.insurance_expiry, v.inspection_status, o.company_name,
                    DATEDIFF(v.insurance_expiry, CURDATE()) AS days_to_expiry
             FROM vehicles v
             LEFT JOIN operators o ON o.id = v.operator_id
             WHERE v.insurance_expiry IS NOT NULL
               AND v.insurance_expiry <= (CURDATE() + INTERVAL ? DAY)
             ORDER BY v.insurance_expiry ASC
             LIMIT 50",
            [max(1, $days)]
        );
    }

    public function countsByType(): array
    {
        return $this->db->select(
            'SELECT vehicle_type AS label, COUNT(*) AS total FROM vehicles GROUP BY vehicle_type ORDER BY total DESC'
        );
    }

    public function countByStatus(): array
    {
        return $this->db->select('SELECT status, COUNT(*) AS total FROM vehicles GROUP BY status');
    }
}
