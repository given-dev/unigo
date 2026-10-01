<?php
/**
 * UniGo - Delivery (goods / parcel) model.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\ActivityLog;
use App\Core\Config;
use App\Core\ConflictException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\Paginator;
use App\Core\ValidationException;
use App\Services\NotificationService;
use App\Services\ReferenceGenerator;

final class DeliveryModel extends BaseModel
{
    protected string $table = 'deliveries';

    public const STATUSES = ['created', 'assigned', 'picked_up', 'in_transit', 'delivered', 'cancelled'];

    /** Allowed transitions - enforced server side. */
    public const STATUS_FLOW = [
        'created'    => ['assigned', 'cancelled'],
        'assigned'   => ['picked_up', 'cancelled'],
        'picked_up'  => ['in_transit', 'cancelled'],
        'in_transit' => ['delivered', 'cancelled'],
        'delivered'  => [],
        'cancelled'  => [],
    ];

    public function findDetailed(int $id): ?array
    {
        return $this->db->first(
            'SELECT d.*, v.registration_number, v.vehicle_type, o.company_name,
                    u.first_name AS customer_first_name, u.last_name AS customer_last_name,
                    u.phone AS customer_phone, u.email AS customer_email,
                    t.trip_code, t.departure_time
             FROM deliveries d
             LEFT JOIN vehicles v ON v.id = d.vehicle_id
             LEFT JOIN operators o ON o.id = d.operator_id
             LEFT JOIN users u ON u.id = d.customer_id
             LEFT JOIN trips t ON t.id = d.trip_id
             WHERE d.id = ? LIMIT 1',
            [$id]
        );
    }

    public function findByTracking(string $tracking): ?array
    {
        $row = $this->findBy('tracking_number', strtoupper(trim($tracking)));
        return $row ? $this->findDetailed((int) $row['id']) : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $deliveryId): array
    {
        return $this->db->select(
            'SELECT dt.*, u.first_name, u.last_name
             FROM delivery_tracking dt
             LEFT JOIN users u ON u.id = dt.recorded_by
             WHERE dt.delivery_id = ?
             ORDER BY dt.recorded_at ASC, dt.id ASC',
            [$deliveryId]
        );
    }

    /** Create a delivery request plus its first tracking event. */
    public function createDelivery(array $data, int $customerId): array
    {
        return $this->db->transaction(function () use ($data, $customerId): array {
            $weight = max(0.1, (float) ($data['weight_kg'] ?? 1));

            // Demo rate card. A production system would resolve this from a
            // distance matrix and a versioned tariff table.
            $price = (float) Config::get('domain.fare_base', 2000) + ($weight * (float) Config::get('domain.fare_per_km', 900) * 0.05);
            $price = max(2000, round($price, 0));

            $tracking = ReferenceGenerator::generate('delivery');

            $id = $this->create([
                'tracking_number'    => $tracking,
                'customer_id'        => $customerId,
                'recipient_name'     => $data['recipient_name'],
                'recipient_phone'    => $data['recipient_phone'],
                'pickup_address'     => $data['pickup_address'],
                'pickup_latitude'    => $data['pickup_latitude'] ?: null,
                'pickup_longitude'   => $data['pickup_longitude'] ?: null,
                'dropoff_address'    => $data['dropoff_address'],
                'dropoff_latitude'   => $data['dropoff_latitude'] ?: null,
                'dropoff_longitude'  => $data['dropoff_longitude'] ?: null,
                'parcel_description' => $data['parcel_description'],
                'weight_kg'          => $weight,
                'is_fragile'         => (int) ($data['is_fragile'] ?? 0),
                'declared_value'     => (float) ($data['declared_value'] ?? 0),
                'price'              => $price,
                'status'             => 'created',
            ]);

            $this->addTracking($id, 'created', 'Delivery request created', (string) $data['pickup_address'], null, null, $customerId);
            NotificationService::deliveryStatus($customerId, $tracking, 'created');

            return $this->findDetailed($id) ?? [];
        });
    }

    public function addTracking(int $deliveryId, string $status, string $description = '', string $locationName = '', ?float $lat = null, ?float $lon = null, ?int $actorId = null): int
    {
        return $this->db->insert('delivery_tracking', [
            'delivery_id'   => $deliveryId,
            'status'        => $status,
            'description'   => mb_substr($description, 0, 255),
            'latitude'      => $lat,
            'longitude'     => $lon,
            'location_name' => mb_substr($locationName, 0, 150),
            'recorded_by'   => $actorId,
            'recorded_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** Validated status transition with tracking + notification. */
    public function updateStatus(int $deliveryId, string $status, array $data = [], ?int $actorId = null): array
    {
        $delivery = $this->findDetailed($deliveryId);
        if (!$delivery) {
            throw new NotFoundException('Parcel not found.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Unknown parcel status.');
        }
        if (!in_array($status, self::STATUS_FLOW[$delivery['status']] ?? [], true)) {
            throw new ConflictException(
                'A parcel that is ' . str_replace('_', ' ', (string) $delivery['status'])
                . ' cannot be marked as ' . str_replace('_', ' ', $status) . '.'
            );
        }

        $update = ['status' => $status];
        if ($status === 'delivered') {
            $update['delivered_at']     = date('Y-m-d H:i:s');
            $update['proof_of_delivery'] = (string) ($data['proof_of_delivery'] ?? '');
            $update['signature_by']      = (string) ($data['signature_by'] ?? $delivery['recipient_name']);
        }
        $this->updateById($deliveryId, $update);

        $this->addTracking(
            $deliveryId,
            $status,
            $data['description'] ?? ('Status changed to ' . str_replace('_', ' ', $status)),
            (string) ($data['location_name'] ?? ''),
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            $actorId
        );

        NotificationService::deliveryStatus(
            (int) $delivery['customer_id'],
            (string) $delivery['tracking_number'],
            $status
        );

        ActivityLog::record(
            ActivityLog::DELIVERY_UPDATED,
            $deliveryId,
            'delivery',
            'Parcel ' . $delivery['tracking_number'] . ' moved to ' . $status
        );

        return $this->findDetailed($deliveryId) ?? [];
    }

    public function assign(int $deliveryId, int $vehicleId, ?int $tripId, ?int $operatorId, ?int $actorId): array
    {
        $this->updateById($deliveryId, [
            'vehicle_id'  => $vehicleId,
            'trip_id'     => $tripId,
            'operator_id' => $operatorId,
        ]);
        return $this->updateStatus($deliveryId, 'assigned', [
            'description' => 'Assigned to a vehicle and scheduled on a trip.',
        ], $actorId);
    }

    /**
     * @param array{customer_id?:int,status?:string,search?:string,operator_id?:int,vehicle_id?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function paginateDeliveries(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['customer_id'])) {
            $where[] = 'd.customer_id = ?';
            $params[] = (int) $filters['customer_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'd.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['operator_id'])) {
            $where[] = 'd.operator_id = ?';
            $params[] = (int) $filters['operator_id'];
        }
        if (!empty($filters['vehicle_id'])) {
            $where[] = 'd.vehicle_id = ?';
            $params[] = (int) $filters['vehicle_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(d.tracking_number LIKE ? OR d.recipient_name LIKE ? OR d.pickup_address LIKE ? OR d.dropoff_address LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return $this->paginateQuery([
            'select' => 'd.*, v.registration_number, o.company_name,
                         u.first_name AS customer_first_name, u.last_name AS customer_last_name',
            'from'   => 'deliveries d',
            'joins'  => 'LEFT JOIN vehicles v ON v.id = d.vehicle_id
                         LEFT JOIN operators o ON o.id = d.operator_id
                         LEFT JOIN users u ON u.id = d.customer_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'd.created_at DESC',
        ], $page, $perPage);
    }

    public function countByStatus(): array
    {
        return $this->db->select('SELECT status, COUNT(*) AS total FROM deliveries GROUP BY status');
    }

    public function revenueThisMonth(): float
    {
        return (float) $this->db->value(
            "SELECT COALESCE(SUM(price),0) FROM deliveries
             WHERE status = 'delivered' AND delivered_at >= (CURDATE() - INTERVAL 30 DAY)"
        );
    }
}
