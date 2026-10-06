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
    public const MAX_PHOTOS = 8;

    /** SQL expression returning a vehicle's cover photo (query must alias vehicles as $alias). */
    public static function coverSql(string $alias = 'v'): string
    {
        return "(SELECT vi.image_path FROM vehicle_images vi WHERE vi.vehicle_id = {$alias}.id
                 ORDER BY vi.sort_order ASC, vi.id ASC LIMIT 1) AS vehicle_image";
    }

    /** All photos for one vehicle, cover first. */
    public function images(int $vehicleId): array
    {
        return $this->db->select(
            'SELECT id, vehicle_id, image_path, caption FROM vehicle_images WHERE vehicle_id = ? ORDER BY sort_order ASC, id ASC',
            [$vehicleId]
        );
    }

    /** Photos grouped by vehicle_id for a page of rows. @return array<int,array<int,array<string,mixed>>> */
    public function photosFor(array $vehicleIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $vehicleIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $grouped = [];
        foreach ($this->db->select(
            "SELECT id, vehicle_id, image_path FROM vehicle_images WHERE vehicle_id IN ($in) ORDER BY sort_order ASC, id ASC",
            $ids
        ) as $row) {
            $grouped[(int) $row['vehicle_id']][] = $row;
        }
        return $grouped;
    }

    public function photoCount(int $vehicleId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM vehicle_images WHERE vehicle_id = ?', [$vehicleId]);
    }

    public function addImage(int $vehicleId, string $path, string $caption = ''): int
    {
        return $this->db->insert('vehicle_images', [
            'vehicle_id' => $vehicleId,
            'image_path' => $path,
            'caption'    => mb_substr($caption, 0, 120),
            'sort_order' => $this->photoCount($vehicleId),
        ]);
    }

    /** Removes the row and returns the stored path so the caller can unlink the file. */
    public function removeImage(int $imageId, int $vehicleId): ?string
    {
        $image = $this->db->first(
            'SELECT id, image_path FROM vehicle_images WHERE id = ? AND vehicle_id = ?',
            [$imageId, $vehicleId]
        );
        if (!$image) {
            return null;
        }
        $this->db->delete('vehicle_images', 'id = ?', [$imageId]);
        return (string) $image['image_path'];
    }


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
        $liveFilter = \App\Core\Config::get('domain.demo_mode', false) ? '' : " AND vl.is_simulated=0 AND vl.recorded_at >= DATE_SUB(NOW(),INTERVAL 5 MINUTE)";
        return $this->db->select(
            "SELECT v.id, v.registration_number, v.vehicle_type, v.status,
                    vl.latitude, vl.longitude, vl.speed, vl.heading, vl.recorded_at, vl.is_simulated, vl.trip_id,
                    o.company_name
             FROM vehicles v
             LEFT JOIN operators o ON o.id = v.operator_id
             LEFT JOIN vehicle_locations vl ON vl.id = (
                 SELECT id FROM vehicle_locations WHERE vehicle_id = v.id ORDER BY recorded_at DESC, id DESC LIMIT 1
             )
             WHERE v.status IN ('active','on_trip')
               AND vl.latitude IS NOT NULL $liveFilter
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
            $params = [];
            foreach ($rows as $row) {
                array_push($params, ...array_values($row));
            }
            $this->db->run(
                'INSERT INTO seats (vehicle_id, seat_number, seat_type, row_number, is_active) VALUES '
                . implode(',', array_map(static fn (array $r) => '(?,?,?,?,?)', $rows)),
                $params
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
