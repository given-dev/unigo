<?php
/**
 * UniGo - Traffic / congestion model.
 *
 * The authority logs congestion, accidents and roadworks against a route.
 * High or critical reports can trigger a diversion: the model works out the
 * closest active alternate routes and notifies the passengers, drivers and
 * operator who are booked on the affected route.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\ValidationException;
use App\Services\NotificationService;

final class TrafficModel extends BaseModel
{
    protected string $table = 'traffic_reports';

    public const CATEGORIES = ['congestion', 'accident', 'roadworks', 'weather', 'flooding', 'event', 'other'];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    public const STATUSES   = ['open', 'monitoring', 'cleared'];

    /**
     * @param array<string,mixed> $data
     */
    public function createReport(array $data, int $userId): int
    {
        $category = (string) ($data['category'] ?? 'congestion');
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new ValidationException('Choose a valid traffic category.');
        }
        $severity = (string) ($data['severity'] ?? 'medium');
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new ValidationException('Choose a valid traffic severity.');
        }

        return $this->create([
            'route_id'      => $data['route_id'] ?? null,
            'location_name' => mb_substr((string) ($data['location_name'] ?? ''), 0, 150),
            'latitude'      => $data['latitude'] ?? null,
            'longitude'     => $data['longitude'] ?? null,
            'category'      => $category,
            'severity'      => $severity,
            'delay_minutes' => max(0, min(1440, (int) ($data['delay_minutes'] ?? 0))),
            'description'   => mb_substr((string) ($data['description'] ?? ''), 0, 500),
            'source'        => 'manual',
            'reported_by'   => $userId,
            'status'        => 'open',
        ]);
    }

    public function updateStatus(int $id, string $status, int $actorId): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Unknown traffic status.');
        }
        $data = ['status' => $status];
        if ($status === 'cleared') {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }
        $this->updateById($id, $data);
    }

    /**
     * Work out the best alternate routes for a congested route: active routes
     * that share the same origin or destination, nearest first.
     *
     * @param array<string,mixed> $route
     * @return array<int,array<string,mixed>>
     */
    public function alternatesForRoute(array $route, int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        $lat = (float) ($route['origin_latitude'] ?? 0);
        $lon = (float) ($route['origin_longitude'] ?? 0);

        return $this->db->select(
            "SELECT r.id, r.route_code, r.name, r.origin_name, r.destination_name,
                    r.distance_km, r.base_fare, r.duration_minutes, r.colour,
                    ((r.origin_name = ?) + (r.destination_name = ?)) AS shared,
                    (6371 * ACOS(LEAST(1, COS(RADIANS(?)) * COS(RADIANS(r.origin_latitude)) *
                      COS(RADIANS(r.origin_longitude) - RADIANS(?)) +
                      SIN(RADIANS(?)) * SIN(RADIANS(r.origin_latitude))))) AS origin_distance_km
             FROM routes r
             WHERE r.status = 'active' AND r.id <> ?
               AND (r.origin_name = ? OR r.destination_name = ?)
             ORDER BY shared DESC, origin_distance_km ASC, r.distance_km ASC
             LIMIT $limit",
            [
                (string) ($route['origin_name'] ?? ''), (string) ($route['destination_name'] ?? ''),
                $lat, $lon, $lat,
                (int) ($route['id'] ?? 0),
                (string) ($route['origin_name'] ?? ''), (string) ($route['destination_name'] ?? ''),
            ]
        );
    }

    /**
     * Suggested diversions for every open / monitored report that has a route.
     *
     * @param array<int,array<string,mixed>> $reports
     * @return array<int,array{report:array<string,mixed>,route:array<string,mixed>,alternates:array<int,array<string,mixed>>}>
     */
    public function suggestForOpenReports(array $reports): array
    {
        $out = [];
        foreach ($reports as $report) {
            if (($report['status'] ?? 'open') === 'cleared' || empty($report['route_id'])) {
                continue;
            }
            $route = $this->db->first('SELECT * FROM routes WHERE id = ?', [(int) $report['route_id']]);
            if (!$route) {
                continue;
            }
            $alternates = $this->alternatesForRoute($route, 3);
            if ($alternates) {
                $out[] = ['report' => $report, 'route' => $route, 'alternates' => $alternates];
            }
        }
        return $out;
    }

    /**
     * Notify every user affected by a route (passengers, drivers, operator)
     * that a diversion is in effect and which alternate route to take.
     *
     * @param array<string,mixed> $route
     * @param array<int,array<string,mixed>> $alternates
     * @return int number of users notified
     */
    public function notifyDiversion(array $route, array $alternates, int $actorId): int
    {
        if (!$alternates) {
            return 0;
        }
        $routeId = (int) $route['id'];
        $altNames = implode(', ', array_slice(array_map(static fn ($a) => (string) $a['name'], $alternates), 0, 2));
        $title = 'Traffic diversion on ' . ($route['route_code'] ?? $route['name']);
        $message = 'Delays reported on ' . $route['name'] . '. Consider the alternate route: ' . $altNames . '.';

        $passengers = $this->db->select(
            "SELECT DISTINCT b.passenger_id AS user_id
             FROM bookings b INNER JOIN trips t ON t.id = b.trip_id
             WHERE t.route_id = ? AND t.status IN ('scheduled','boarding','in_transit')
               AND b.status IN ('pending','confirmed')",
            [$routeId]
        );
        $drivers = $this->db->select(
            "SELECT DISTINCT d.user_id
             FROM trips t INNER JOIN drivers d ON d.id = t.driver_id
             WHERE t.route_id = ? AND t.status IN ('scheduled','boarding','in_transit') AND d.user_id IS NOT NULL",
            [$routeId]
        );
        $operators = $this->db->select(
            'SELECT DISTINCT user_id FROM operators WHERE id = ? AND user_id IS NOT NULL',
            [(int) ($route['operator_id'] ?? 0)]
        );

        $targets = [];
        foreach ($passengers as $row) {
            if (!empty($row['user_id'])) {
                $targets[(int) $row['user_id']] = '/bookings';
            }
        }
        foreach ($drivers as $row) {
            if (!empty($row['user_id'])) {
                $targets[(int) $row['user_id']] = '/driver/tracking';
            }
        }
        foreach ($operators as $row) {
            if (!empty($row['user_id'])) {
                $targets[(int) $row['user_id']] = '/operator/trips';
            }
        }

        $sent = 0;
        foreach ($targets as $userId => $link) {
            if ($userId === $actorId) {
                continue;
            }
            NotificationService::push($userId, 'traffic', $title, $message, $link, 'warning', 'route');
            $sent++;
        }
        return $sent;
    }
}
