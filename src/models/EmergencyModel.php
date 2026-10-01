<?php
/**
 * UniGo - SOS / emergency model.
 *
 * Notes on safety behaviour:
 *   - An SOS is always created from a deliberate, confirmed user action.
 *   - Creating an alert also notifies administrators and, when the vehicle /
 *     driver is known, the assigned driver and operator.
 *   - Resolving an alert requires an operator to leave a resolution note.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\ActivityLog;
use App\Core\Config;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\ValidationException;
use App\Services\NotificationService;
use App\Services\ReferenceGenerator;

final class EmergencyModel extends BaseModel
{
    protected string $table = 'emergency_alerts';

    public const TYPES = ['accident', 'medical', 'security', 'breakdown', 'harassment', 'missing_person', 'other'];
    public const STATUSES = ['new', 'investigating', 'responding', 'resolved', 'dismissed'];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /**
     * Raise an alert.
     *
     * @param array{
     *   emergency_type?:string, description?:string, latitude?:float, longitude?:float,
     *   vehicle_id?:int, trip_id?:int, address_text?:string, contact_phone?:string,
     *   severity?:string, notify_police?:bool
     * } $data
     */
    public function raise(int $userId, array $data): array
    {
        $type = (string) ($data['emergency_type'] ?? 'other');
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException('Please choose a valid emergency type.');
        }
        $severity = (string) ($data['severity'] ?? 'high');
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'high';
        }

        return $this->db->transaction(function () use ($userId, $data, $type, $severity): array {
            $reference = ReferenceGenerator::generate('emergency');

            // Resolve the vehicle/trip context from an active booking so the
            // alert always points at the right journey.
            $vehicleId = $data['vehicle_id'] ?? null;
            $tripId    = $data['trip_id'] ?? null;
            $phone     = (string) ($data['contact_phone'] ?? '');

            if (!$vehicleId || !$tripId) {
                $context = $this->db->first(
                    "SELECT b.vehicle_id, b.trip_id
                     FROM bookings b
                     INNER JOIN trips t ON t.id = b.trip_id
                     WHERE b.passenger_id = ?
                       AND b.status IN ('pending','confirmed')
                       AND t.departure_time >= (NOW() - INTERVAL 4 HOUR)
                     ORDER BY b.booked_at DESC LIMIT 1",
                    [$userId]
                );
                $vehicleId = $vehicleId ?: ($context['vehicle_id'] ?? null);
                $tripId    = $tripId ?: ($context['trip_id'] ?? null);
            }
            if (!$phone) {
                $phone = (string) $this->db->value('SELECT phone FROM users WHERE id = ?', [$userId]);
            }

            $id = $this->create([
                'reference'       => $reference,
                'user_id'         => $userId,
                'vehicle_id'      => $vehicleId,
                'trip_id'         => $tripId,
                'emergency_type'  => $type,
                'description'     => mb_substr((string) ($data['description'] ?? ''), 0, 500),
                'latitude'        => isset($data['latitude']) ? (float) $data['latitude'] : null,
                'longitude'       => isset($data['longitude']) ? (float) $data['longitude'] : null,
                'address_text'    => mb_substr((string) ($data['address_text'] ?? ''), 0, 190),
                'contact_phone'   => $phone,
                'status'          => 'new',
                'severity'        => $severity,
                'notify_police'   => (int) ($data['notify_police'] ?? 0),
                'is_simulated'    => (int) ($data['is_simulated'] ?? 0),
            ]);

            $this->alertResponders($id, $reference, $type, $vehicleId, $tripId);

            ActivityLog::record(
                ActivityLog::EMERGENCY_RAISED,
                $id,
                'emergency',
                'SOS ' . $reference . ' raised (' . str_replace('_', ' ', $type) . ')',
                $userId
            );

            return $this->findDetailed($id) ?? [];
        });
    }

    /** Notify admins, the operator and the driver on the affected vehicle. */
    private function alertResponders(int $alertId, string $reference, string $type, ?int $vehicleId, ?int $tripId): void
    {
        $admins = $this->db->select(
            "SELECT u.id FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.slug IN ('admin','authority') AND u.status = 'active'
             LIMIT 50"
        );
        foreach ($admins as $admin) {
            NotificationService::push(
                (int) $admin['id'],
                'emergency',
                'SOS raised: ' . $reference,
                str_replace('_', ' ', $type) . ' emergency reported by a UniGo user.',
                '/admin/emergencies',
                'danger',
                'sos'
            );
        }

        if ($vehicleId) {
            $driverId = $this->db->value('SELECT driver_id FROM vehicles WHERE id = ?', [$vehicleId]);
            if ($driverId) {
                $driverUserId = $this->db->value('SELECT user_id FROM drivers WHERE id = ?', [$driverId]);
                if ($driverUserId) {
                    NotificationService::emergencyBroadcast((int) $driverUserId, $reference, $type);
                }
            }
            $operatorId = $this->db->value('SELECT operator_id FROM vehicles WHERE id = ?', [$vehicleId]);
            if ($operatorId) {
                $operatorUserId = $this->db->value('SELECT user_id FROM operators WHERE id = ?', [$operatorId]);
                if ($operatorUserId) {
                    NotificationService::push(
                        (int) $operatorUserId,
                        'emergency',
                        'Emergency on your vehicle',
                        'SOS ' . $reference . ' (' . str_replace('_', ' ', $type) . ') involves one of your vehicles.',
                        '/operator/emergencies',
                        'danger',
                        'sos'
                    );
                }
            }
        }
    }

    public function findDetailed(int $id): ?array
    {
        return $this->db->first(
            'SELECT e.*, u.first_name, u.last_name, u.phone AS user_phone, u.email,
                    v.registration_number, v.vehicle_type,
                    t.trip_code, r.name AS route_name,
                    du.first_name AS driver_first_name, du.last_name AS driver_last_name,
                    o.company_name
             FROM emergency_alerts e
             INNER JOIN users u ON u.id = e.user_id
             LEFT JOIN vehicles v ON v.id = e.vehicle_id
             LEFT JOIN trips t ON t.id = e.trip_id
             LEFT JOIN routes r ON r.id = t.route_id
             LEFT JOIN drivers d ON d.id = v.driver_id
             LEFT JOIN users du ON du.id = d.user_id
             LEFT JOIN operators o ON o.id = v.operator_id
             WHERE e.id = ? LIMIT 1',
            [$id]
        );
    }

    public function updateStatus(int $alertId, string $status, string $note = '', ?int $actorId = null): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Unknown emergency status.');
        }
        $alert = $this->findDetailed($alertId);
        if (!$alert) {
            throw new \App\Core\NotFoundException('Emergency alert not found.');
        }
        if (in_array($status, ['resolved', 'dismissed'], true) && trim($note) === '') {
            throw new ValidationException('Please record what happened before closing this alert.');
        }

        $data = [
            'status'      => $status,
            'handled_by'  => $actorId,
            'handled_at'  => date('Y-m-d H:i:s'),
            'resolution_note' => mb_substr($note, 0, 500),
        ];
        if (in_array($status, ['resolved', 'dismissed'], true)) {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }

        $this->updateById($alertId, $data);

        // Tell the person who raised it what is happening.
        $messages = [
            'investigating' => ['info',  'We are investigating your alert. Stay where you are if it is safe.'],
            'responding'    => ['info',  'A response is on the way. Follow the responder instructions.'],
            'resolved'      => ['success', 'Your emergency alert has been marked as resolved.'],
            'dismissed'     => ['warning', 'Your emergency alert was closed. Contact support if you still need help.'],
        ];
        if (isset($messages[$status])) {
            [$severity, $message] = $messages[$status];
            NotificationService::push(
                (int) $alert['user_id'],
                'emergency',
                'Alert update: ' . $status,
                $message,
                '/passenger/emergency',
                $severity,
                'sos'
            );
        }

        ActivityLog::record(
            ActivityLog::EMERGENCY_UPDATED,
            $alertId,
            'emergency',
            'Alert ' . $alert['reference'] . ' moved to ' . $status,
            $actorId
        );

        return $this->findDetailed($alertId) ?? [];
    }

    /**
     * @param array{status?:string,type?:string,search?:string,severity?:string} $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function paginateAlerts(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'e.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'e.emergency_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'e.severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(e.reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR e.description LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return $this->paginateQuery([
            'select' => 'e.*, u.first_name, u.last_name, u.phone AS user_phone,
                         v.registration_number, o.company_name, t.trip_code',
            'from'   => 'emergency_alerts e',
            'joins'  => 'INNER JOIN users u ON u.id = e.user_id
                         LEFT JOIN vehicles v ON v.id = e.vehicle_id
                         LEFT JOIN operators o ON o.id = v.operator_id
                         LEFT JOIN trips t ON t.id = e.trip_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => "FIELD(e.status,'new','investigating','responding','resolved','dismissed'), e.created_at DESC",
        ], $page, $perPage);
    }

    public function openCount(): int
    {
        return $this->db->count("SELECT COUNT(*) FROM emergency_alerts WHERE status IN ('new','investigating','responding')");
    }

    public function openAlerts(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        return $this->db->select(
            "SELECT e.*, u.first_name, u.last_name, v.registration_number
             FROM emergency_alerts e
             INNER JOIN users u ON u.id = e.user_id
             LEFT JOIN vehicles v ON v.id = e.vehicle_id
             WHERE e.status IN ('new','investigating','responding')
             ORDER BY FIELD(e.severity,'critical','high','medium','low'), e.created_at DESC
             LIMIT $limit"
        );
    }

    public function typeBreakdown(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return $this->db->select(
            'SELECT emergency_type AS label, COUNT(*) AS total FROM emergency_alerts
             WHERE created_at >= (CURDATE() - INTERVAL ? DAY) GROUP BY emergency_type',
            [$days]
        );
    }

    public function monthlySeries(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $start  = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));

        $rows = $this->db->select(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total
             FROM emergency_alerts
             WHERE created_at >= ? AND created_at < (CURDATE() + INTERVAL 1 DAY)
             GROUP BY month ORDER BY month ASC",
            [$start]
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['month']] = (int) $row['total'];
        }

        $series = [];
        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $stamp = strtotime('-' . $offset . ' months');
            $key   = date('Y-m', $stamp);
            $series[] = [
                'month' => $key,
                'label' => date('M Y', $stamp),
                'total' => $byMonth[$key] ?? 0,
            ];
        }

        return $series;
    }
}
