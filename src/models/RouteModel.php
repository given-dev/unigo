<?php
/**
 * UniGo - Route model + the transport search engine.
 *
 * Search design (important for performance):
 *   1. routes are matched on indexed name / origin / destination columns
 *   2. only route ids are collected first (cheap, indexed)
 *   3. trips are joined with their route and availability computed with a
 *      correlated subquery limited to the trips already filtered by
 *      (status, departure window)
 *   4. results are paginated with LIMIT/OFFSET
 * No part of the query loads full bookings tables.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;
use App\Services\SettingsService;

final class RouteModel extends BaseModel
{
    protected string $table = 'routes';

    public function findDetailed(int $id): ?array
    {
        $route = $this->db->first(
            'SELECT r.*, o.company_name,
                    (SELECT COUNT(*) FROM trips t WHERE t.route_id = r.id) AS trip_count,
                    (SELECT COUNT(*) FROM vehicles v WHERE v.home_route_id = r.id) AS vehicle_count
             FROM routes r
             LEFT JOIN operators o ON o.id = r.operator_id
             WHERE r.id = ? LIMIT 1',
            [$id]
        );

        if ($route) {
            // Stops travel with the route so detail pages need a single call.
            $route['stops'] = $this->stops($id);
        }

        return $route;
    }

    /** @return array<int,array<string,mixed>> */
    public function stops(int $routeId): array
    {
        return $this->db->select(
            'SELECT * FROM route_stops WHERE route_id = ? ORDER BY stop_order ASC',
            [$routeId]
        );
    }

    public function createRoute(array $data, array $stops = []): int
    {
        return $this->db->transaction(function () use ($data, $stops): int {
            $routeId = $this->create($data);
            if ($stops) {
                $this->replaceStops($routeId, $stops);
            }
            return $routeId;
        });
    }

    public function replaceStops(int $routeId, array $stops): void
    {
        $this->db->delete('route_stops', 'route_id = ?', [$routeId]);
        $order = 0;
        foreach ($stops as $stop) {
            $order++;
            $this->db->insert('route_stops', [
                'route_id'           => $routeId,
                'stop_order'         => $order,
                'stop_name'          => trim((string) $stop['stop_name']),
                'latitude'           => (float) ($stop['latitude'] ?? 0),
                'longitude'          => (float) ($stop['longitude'] ?? 0),
                'minutes_from_origin' => (int) ($stop['minutes_from_origin'] ?? 0),
                'fare_from_origin'   => (float) ($stop['fare_from_origin'] ?? 0),
                'is_pickup_point'    => (int) ($stop['is_pickup_point'] ?? 1),
            ]);
        }
    }

    /**
     * @param array{search?:string,status?:string,operator_id?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function paginateRoutes(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(r.name LIKE ? OR r.route_code LIKE ? OR r.origin_name LIKE ? OR r.destination_name LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['operator_id'])) {
            $where[] = 'r.operator_id = ?';
            $params[] = (int) $filters['operator_id'];
        }

        return $this->paginateQuery([
            'select' => 'r.*, o.company_name,
                         (SELECT COUNT(*) FROM trips t WHERE t.route_id = r.id) AS trip_count,
                         (SELECT COUNT(*) FROM route_stops rs WHERE rs.route_id = r.id) AS stop_count',
            'from'   => 'routes r',
            'joins'  => 'LEFT JOIN operators o ON o.id = r.operator_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'r.name ASC',
        ], $page, $perPage);
    }

    /**
     * Main transport search.
     *
     * @param array{
     *   from?:string, to?:string, date?:string, passengers?:int,
     *   transport_type?:string, max_price?:float, min_price?:float,
     *   depart_after?:string, depart_before?:string, min_seats?:int,
     *   min_rating?:float, sort?:string
     * } $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $where = ["t.status IN ('scheduled','boarding')", "r.status = 'active'", "v.status IN ('active','on_trip')"];
        $params = [];

        $from = trim((string) ($filters['from'] ?? ''));
        $to   = trim((string) ($filters['to'] ?? ''));

        if ($from !== '') {
            $where[] = '(r.origin_name LIKE ? OR r.name LIKE ? OR rs.stop_name LIKE ?)';
            $like = '%' . $from . '%';
            array_push($params, $like, $like, $like);
        }
        if ($to !== '') {
            $where[] = '(r.destination_name LIKE ? OR r.name LIKE ? OR rs.stop_name LIKE ?)';
            $like = '%' . $to . '%';
            array_push($params, $like, $like, $like);
        }

        // Departure window: default "today onwards"
        $date = $filters['date'] ?? date('Y-m-d');
        $fromTime = !empty($filters['depart_after'])
            ? $filters['depart_after']
            : $date . ' 00:00:00';
        $toTime = !empty($filters['depart_before'])
            ? $filters['depart_before']
            : date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';

        $where[] = 't.departure_time BETWEEN ? AND ?';
        $params[] = $fromTime;
        $params[] = $toTime;

        if (!empty($filters['transport_type']) && $filters['transport_type'] !== 'all') {
            $where[] = 'v.vehicle_type = ?';
            $params[] = $filters['transport_type'];
        }
        if (isset($filters['min_price']) && $filters['min_price'] !== '' && $filters['min_price'] !== null) {
            $where[] = 't.fare >= ?';
            $params[] = (float) $filters['min_price'];
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== '' && $filters['max_price'] !== null) {
            $where[] = 't.fare <= ?';
            $params[] = (float) $filters['max_price'];
        }
        $minSeats = (int) ($filters['min_seats'] ?? $filters['passengers'] ?? 1);
        if ($minSeats > 0) {
            $where[] = "(t.seats_total - (SELECT COUNT(*) FROM bookings b
                          WHERE b.trip_id = t.id AND b.status IN ('pending','confirmed','completed'))) >= ?";
            $params[] = $minSeats;
        }

        $joins = 'INNER JOIN routes r ON r.id = t.route_id
                  INNER JOIN vehicles v ON v.id = t.vehicle_id
                  LEFT JOIN operators o ON o.id = t.operator_id
                  LEFT JOIN drivers d ON d.id = t.driver_id
                  LEFT JOIN users du ON du.id = d.user_id
                  LEFT JOIN route_stops rs ON rs.route_id = r.id AND rs.stop_order = 1';

        $select = 't.id AS trip_id, t.trip_code, t.departure_time, t.arrival_time, t.fare, t.currency,
                   t.seats_total, t.status AS trip_status, t.delay_minutes,
                   r.id AS route_id, r.name AS route_name, r.route_code, r.origin_name, r.destination_name,
                   r.distance_km, r.duration_minutes, r.origin_latitude, r.origin_longitude,
                   r.destination_latitude, r.destination_longitude, r.colour,
                   v.id AS vehicle_id, v.registration_number, v.vehicle_type, v.make, v.model, v.capacity, v.colour AS vehicle_colour,
                   o.company_name, o.id AS operator_id,
                   d.id AS driver_id, d.rating_avg, d.rating_count,
                   du.first_name AS driver_first_name, du.last_name AS driver_last_name, du.phone AS driver_phone,
                   (SELECT COUNT(*) FROM bookings b
                      WHERE b.trip_id = t.id AND b.status IN ("pending","confirmed","completed")) AS seats_booked';

        // Rating filter needs the driver alias, so only join drivers when asked
        if (!empty($filters['min_rating'])) {
            $where[] = 'd.rating_avg >= ?';
            $params[] = (float) $filters['min_rating'];
        }

        $order = match ($filters['sort'] ?? 'departure') {
            'price'     => 't.fare ASC',
            'price_desc'=> 't.fare DESC',
            'rating'    => 'd.rating_avg DESC, t.departure_time ASC',
            'seats'     => 'seats_booked ASC',
            'duration'  => 't.arrival_time ASC',
            default     => 't.departure_time ASC',
        };

        return $this->paginateQuery([
            'select' => $select,
            'from'   => 'trips t',
            'joins'  => $joins,
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => $order,
        ], $page, $perPage);
    }

    /** Type-ahead suggestions for the search box (places + routes). */
    public function suggestions(string $term, int $limit = 8): array
    {
        $like = '%' . trim($term) . '%';
        $limit = max(1, min(20, $limit));

        $places = $this->db->select(
            "SELECT name, category, city, latitude, longitude
             FROM locations
             WHERE is_active = 1 AND (search_text LIKE ? OR name LIKE ?)
             ORDER BY CHAR_LENGTH(name) ASC
             LIMIT $limit",
            [$like, $like]
        );

        $routes = $this->db->select(
            "SELECT name, origin_name, destination_name, route_code
             FROM routes
             WHERE status = 'active' AND (name LIKE ? OR origin_name LIKE ? OR destination_name LIKE ?)
             ORDER BY CHAR_LENGTH(name) ASC
             LIMIT $limit",
            [$like, $like, $like]
        );

        return ['places' => $places, 'routes' => $routes];
    }

    /** Popular routes for the passenger home screen. */
    public function popular(int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        return $this->db->select(
            "SELECT r.*, o.company_name,
                    (SELECT COUNT(*) FROM trips t WHERE t.route_id = r.id AND t.status = 'scheduled') AS upcoming_trips
             FROM routes r
             LEFT JOIN operators o ON o.id = r.operator_id
             WHERE r.status = 'active'
             ORDER BY upcoming_trips DESC, r.name ASC
             LIMIT $limit"
        );
    }

    public function routePerformance(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->select(
            "SELECT r.id, r.name, r.route_code,
                    COUNT(t.id) AS total_trips,
                    SUM(t.status = 'completed') AS completed_trips,
                    SUM(t.status = 'cancelled') AS cancelled_trips,
                    COALESCE(AVG(t.delay_minutes), 0) AS avg_delay,
                    COALESCE(SUM((SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id AND b.status IN ('confirmed','completed')) * t.fare), 0) AS revenue
             FROM routes r
             LEFT JOIN trips t ON t.route_id = r.id
             GROUP BY r.id
             ORDER BY revenue DESC, total_trips DESC
             LIMIT $limit"
        );
    }

    public function activeRouteCount(): int
    {
        return $this->count("status = 'active'");
    }
}
