<?php
/**
 * UniGo - Complaint / problem report model.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\ActivityLog;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\ValidationException;
use App\Services\NotificationService;
use App\Services\ReferenceGenerator;

final class ComplaintModel extends BaseModel
{
    protected string $table = 'complaints';

    public const CATEGORIES = [
        'late_departure', 'overcharging', 'vehicle_condition', 'driver_behaviour',
        'cleanliness', 'lost_property', 'safety', 'other',
    ];

    public const STATUSES = ['open', 'investigating', 'resolved', 'dismissed'];

    public function openComplaint(array $data, int $userId): array
    {
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 1000) {
            throw new ValidationException('Describe the problem using at most 1000 characters.');
        }
        $data['description'] = $description;
        $category = (string) ($data['category'] ?? 'other');
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new ValidationException('Please choose a valid complaint category.');
        }
        $severity = in_array($data['severity'] ?? '', ['low', 'medium', 'high'], true)
            ? (string) $data['severity'] : 'medium';

        return $this->db->transaction(function () use ($data, $userId, $category, $severity): array {
            $bookingId = isset($data['booking_id']) && $data['booking_id'] ? (int) $data['booking_id'] : null;

            // Auto-attach trip / vehicle / driver from the booking, and verify
            // the booking actually belongs to this user.
            if ($bookingId) {
                $booking = $this->db->first(
                    'SELECT id, passenger_id, trip_id, vehicle_id, driver_id FROM bookings WHERE id = ?',
                    [$bookingId]
                );
                if (!$booking || (int) $booking['passenger_id'] !== $userId) {
                    throw new \App\Core\AuthorizationException('You cannot report an issue against that booking.');
                }
                $data['trip_id']    = (int) $booking['trip_id'];
                $data['vehicle_id'] = (int) $booking['vehicle_id'];
                $data['driver_id']  = $booking['driver_id'] ? (int) $booking['driver_id'] : null;
            }

            $reference = ReferenceGenerator::generate('complaint');
            $id = $this->create([
                'reference'    => $reference,
                'user_id'      => $userId,
                'booking_id'   => $bookingId,
                'trip_id'      => $data['trip_id'] ?? null,
                'vehicle_id'   => $data['vehicle_id'] ?? null,
                'driver_id'    => $data['driver_id'] ?? null,
                'category'     => $category,
                'severity'     => $severity,
                'description'  => mb_substr((string) $data['description'], 0, 1000),
                'status'       => 'open',
            ]);

            // Notify administrators.
            $admins = $this->db->select(
                "SELECT u.id FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id
                 WHERE r.slug = 'admin' AND u.status = 'active' LIMIT 20"
            );
            foreach ($admins as $admin) {
                NotificationService::push(
                    (int) $admin['id'],
                    'complaint',
                    'New complaint ' . $reference,
                    str_replace('_', ' ', $category) . ' reported by a user.',
                    '/admin/complaints',
                    'warning',
                    'flag'
                );
            }

            ActivityLog::record(ActivityLog::COMPLAINT_CREATED, $id, 'complaint', 'Complaint ' . $reference, $userId);

            return $this->findDetailed($id) ?? [];
        });
    }

    public function findDetailed(int $id): ?array
    {
        return $this->db->first(
            'SELECT c.*, u.first_name, u.last_name, u.email, u.phone,
                    v.registration_number, t.trip_code, b.reference AS booking_reference,
                    du.first_name AS driver_first_name, du.last_name AS driver_last_name,
                    hu.first_name AS handler_first_name, hu.last_name AS handler_last_name
             FROM complaints c
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN bookings b ON b.id = c.booking_id
             LEFT JOIN trips t ON t.id = c.trip_id
             LEFT JOIN vehicles v ON v.id = c.vehicle_id
             LEFT JOIN drivers d ON d.id = c.driver_id
             LEFT JOIN users du ON du.id = d.user_id
             LEFT JOIN users hu ON hu.id = c.handled_by
             WHERE c.id = ? LIMIT 1',
            [$id]
        );
    }

    public function resolve(int $id, string $status, string $resolution, ?int $actorId): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Unknown complaint status.');
        }
        $complaint = $this->findDetailed($id);
        if (!$complaint) {
            throw new \App\Core\NotFoundException('Complaint not found.');
        }

        $data = [
            'status'      => $status,
            'handled_by'  => $actorId,
            'resolution'  => mb_substr($resolution, 0, 500),
        ];
        if (in_array($status, ['resolved', 'dismissed'], true)) {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }
        $this->updateById($id, $data);

        NotificationService::push(
            (int) $complaint['user_id'],
            'complaint',
            'Complaint update: ' . $status,
            'Your complaint ' . $complaint['reference'] . ' is now ' . $status . '.',
            '/passenger/complaints',
            $status === 'resolved' ? 'success' : 'info',
            'flag'
        );

        return $this->findDetailed($id) ?? [];
    }

    /**
     * @param array{status?:string,category?:string,search?:string} $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function paginateComplaints(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'c.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'c.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR c.description LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return $this->paginateQuery([
            'select' => 'c.*, u.first_name, u.last_name, u.email, v.registration_number, t.trip_code',
            'from'   => 'complaints c',
            'joins'  => 'INNER JOIN users u ON u.id = c.user_id
                         LEFT JOIN vehicles v ON v.id = c.vehicle_id
                         LEFT JOIN trips t ON t.id = c.trip_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => "FIELD(c.status,'open','investigating','resolved','dismissed'), c.created_at DESC",
        ], $page, $perPage);
    }

    public function openCount(): int
    {
        return $this->db->count("SELECT COUNT(*) FROM complaints WHERE status IN ('open','investigating')");
    }
}
