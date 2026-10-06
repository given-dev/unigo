<?php
/**
 * UniGo - Statistics model.
 *
 * Every dashboard tile and chart comes from here. Design rules:
 *   - one aggregated SQL query per tile/chart (never row-by-row in PHP)
 *   - all list endpoints paginated
 *   - date ranges are bounded and indexed (created_at / departure_time)
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\BaseModel;

final class StatsModel extends BaseModel
{
    protected string $table = 'users';

    // ------------------------------------------------------------------
    // Admin / authority tiles
    // ------------------------------------------------------------------

    public function headline(): array
    {
        $db = $this->db;
        return [
            'total_users' => (int) $db->value("SELECT COUNT(*) FROM users"),
            'active_drivers' => (int) $db->value("SELECT COUNT(*) FROM drivers WHERE status IN ('available','on_trip')"),
            'total_drivers' => (int) $db->value('SELECT COUNT(*) FROM drivers'),
            'total_passengers' => (int) $db->value('SELECT COUNT(*) FROM passengers'),
            'registered_vehicles' => (int) $db->value('SELECT COUNT(*) FROM vehicles'),
            'active_vehicles' => (int) $db->value("SELECT COUNT(*) FROM vehicles WHERE status IN ('active','on_trip')"),
            'active_trips' => (int) $db->value("SELECT COUNT(*) FROM trips WHERE status IN ('boarding','in_transit')"),
            'total_trips' => (int) $db->value('SELECT COUNT(*) FROM trips'),
            'today_bookings' => (int) $db->value('SELECT COUNT(*) FROM bookings WHERE DATE(booked_at) = CURDATE()'),
            'today_revenue' => (float) $db->value(
                "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'successful' AND DATE(created_at) = CURDATE()"
            ),
            'active_operators' => (int) $db->value("SELECT COUNT(*) FROM operators WHERE approval_status = 'approved'"),
            'pending_operators' => (int) $db->value("SELECT COUNT(*) FROM operators WHERE approval_status = 'pending'"),
            'open_emergencies' => (int) $db->value("SELECT COUNT(*) FROM emergency_alerts WHERE status IN ('new','investigating','responding')"),
            'open_complaints' => (int) $db->value("SELECT COUNT(*) FROM complaints WHERE status IN ('open','investigating')"),
            'active_routes' => (int) $db->value("SELECT COUNT(*) FROM routes WHERE status = 'active'"),
            'active_deliveries' => (int) $db->value("SELECT COUNT(*) FROM deliveries WHERE status NOT IN ('delivered','cancelled')"),
        ];
    }

    /** Registrations per day for the last N days. */
    public function usersOverTime(int $days = 30): array
    {
        return $this->dailySeriesQuery('users', 'created_at', [
            'total' => 'COUNT(*)',
        ], $days);
    }

    /** Driver and operator dashboards must describe their own workspace. */
    public function workspaceHeadline(string $role, int $profileId): array
    {
        if (!in_array($role, ['driver', 'operator'], true)) {
            throw new \InvalidArgumentException('Unknown workspace role.');
        }
        $column = $role === 'driver' ? 'driver_id' : 'operator_id';
        $trips = $this->db->first(
            "SELECT COUNT(*) AS total_trips, COALESCE(SUM(status IN ('boarding','in_transit')),0) AS active_trips
             FROM trips WHERE $column = ?", [$profileId]
        );
        $trips['today_bookings'] = $this->db->count(
            "SELECT COUNT(*) FROM bookings b JOIN trips t ON t.id = b.trip_id WHERE t.$column = ? AND DATE(b.booked_at) = CURDATE()", [$profileId]
        );
        if ($role === 'driver') {
            $trips['active_routes'] = $this->db->count('SELECT COUNT(DISTINCT r.id) FROM routes r JOIN trips t ON t.route_id = r.id WHERE t.driver_id = ? AND r.status = "active"', [$profileId]);
        } else {
            $trips['active_vehicles'] = $this->db->count("SELECT COUNT(*) FROM vehicles WHERE operator_id = ? AND status IN ('active','on_trip')", [$profileId]);
            $trips['total_drivers'] = $this->db->count('SELECT COUNT(*) FROM drivers WHERE operator_id = ?', [$profileId]);
            $trips['today_revenue'] = (float) $this->db->value("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN trips t ON t.id = b.trip_id WHERE t.operator_id = ? AND p.status = 'successful' AND DATE(p.created_at) = CURDATE()", [$profileId]);
        }
        return $trips;
    }

    public function bookingsOverTime(int $days = 14): array
    {
        return $this->dailySeriesQuery('bookings', 'booked_at', [
            'total'     => 'COUNT(*)',
            'cancelled' => "SUM(status = 'cancelled')",
        ], $days);
    }

    public function revenueOverTime(int $days = 14): array
    {
        return $this->dailySeriesQuery('payments', 'created_at', [
            'revenue'      => "SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END)",
            'transactions' => 'COUNT(*)',
        ], $days);
    }

    public function transportTypeMix(): array
    {
        return $this->db->select(
            "SELECT v.vehicle_type AS label, COUNT(b.id) AS total
             FROM bookings b
             INNER JOIN vehicles v ON v.id = b.vehicle_id
             WHERE b.booked_at >= (CURDATE() - INTERVAL 30 DAY)
             GROUP BY v.vehicle_type
             ORDER BY total DESC"
        );
    }

    public function tripStatusMix(): array
    {
        return $this->db->select('SELECT status AS label, COUNT(*) AS total FROM trips GROUP BY status');
    }

    /** Top routes by revenue - used by the authority + admin dashboards. */
    public function topRoutes(int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));
        return $this->db->select(
            "SELECT r.id, r.name, r.route_code,
                    COUNT(b.id) AS bookings,
                    COALESCE(SUM(CASE WHEN b.payment_status = 'paid' AND b.status IN ('confirmed','completed') THEN b.fare ELSE 0 END),0) AS revenue
             FROM routes r
             LEFT JOIN trips t ON t.route_id = r.id
             LEFT JOIN bookings b ON b.trip_id = t.id AND b.booked_at >= (CURDATE() - INTERVAL 30 DAY)
             GROUP BY r.id
             ORDER BY revenue DESC, bookings DESC
             LIMIT $limit"
        );
    }

    public function topDrivers(int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));
        return $this->db->select(
            'SELECT d.id, u.first_name, u.last_name, d.rating_avg, d.rating_count,
                    d.total_trips, o.company_name
             FROM drivers d
             INNER JOIN users u ON u.id = d.user_id
             LEFT JOIN operators o ON o.id = d.operator_id
             WHERE d.total_trips > 0
             ORDER BY d.total_trips DESC, d.rating_avg DESC
             LIMIT ' . $limit
        );
    }

    public function operatorPerformance(int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        return $this->db->select(
            "SELECT o.id, o.company_name, o.rating_avg,
                    (SELECT COUNT(*) FROM vehicles v WHERE v.operator_id = o.id) AS vehicles,
                    COUNT(DISTINCT t.id) AS trips,
                    COUNT(b.id) AS bookings,
                    COALESCE(SUM(CASE WHEN b.payment_status = 'paid' AND b.status <> 'cancelled' THEN b.fare ELSE 0 END),0) AS revenue
             FROM operators o
             LEFT JOIN trips t ON t.operator_id = o.id
             LEFT JOIN bookings b ON b.trip_id = t.id AND b.booked_at >= (CURDATE() - INTERVAL 30 DAY)
             WHERE o.approval_status = 'approved'
             GROUP BY o.id
             ORDER BY revenue DESC
             LIMIT $limit"
        );
    }

    // ------------------------------------------------------------------
    // Authority view
    // ------------------------------------------------------------------

    public function trafficSnapshot(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->select(
            "SELECT t.*, r.name AS route_name, r.route_code
             FROM traffic_reports t
             LEFT JOIN routes r ON r.id = t.route_id
             WHERE t.status <> 'cleared'
             ORDER BY FIELD(t.severity,'critical','high','medium','low'), t.created_at DESC
             LIMIT $limit"
        );
    }

    public function congestionByRoute(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->select(
            "SELECT COALESCE(r.name, 'Unassigned') AS label,
                    COUNT(t.id) AS total,
                    COALESCE(AVG(t.delay_minutes),0) AS avg_delay,
                    COALESCE(SUM(t.severity = 'critical'),0) AS critical_count
             FROM traffic_reports t
             LEFT JOIN routes r ON r.id = t.route_id
             WHERE t.status <> 'cleared'
             GROUP BY t.route_id
             ORDER BY critical_count DESC, avg_delay DESC
             LIMIT $limit"
        );
    }

    /** District level trip/passenger distribution (simple geographic rollup). */
    public function tripsByArea(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->select(
            "SELECT COALESCE(r.origin_name, 'Unknown') AS label, COUNT(*) AS total
             FROM trips t INNER JOIN routes r ON r.id = t.route_id
             WHERE t.departure_time >= (CURDATE() - INTERVAL 30 DAY)
             GROUP BY r.origin_name
             ORDER BY total DESC LIMIT $limit"
        );
    }

    // ------------------------------------------------------------------
    // System health (admin settings page)
    // ------------------------------------------------------------------

    public function health(): array
    {
        $db = $this->db;
        $tables = ['users', 'bookings', 'trips', 'payments', 'notifications', 'vehicle_locations', 'activity_logs'];
        $counts = [];
        foreach ($tables as $t) {
            $counts[$t] = (int) $db->value("SELECT COUNT(*) FROM `$t`");
        }

        return [
            'php_version'   => PHP_VERSION,
            'server'        => (string) $db->serverVersion(),
            'db_size_mb'    => (float) $db->value(
                'SELECT ROUND(SUM(data_length + index_length)/1024/1024, 2)
                 FROM information_schema.TABLES WHERE table_schema = DATABASE()'
            ),
            'table_counts'  => $counts,
            'uptime_hint'   => 'Live request data - no cached counters in use',
        ];
    }

    /** Realtime-ish activity feed for the admin dashboard. */
    public function liveFeed(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->select(
            "SELECT 'booking' AS kind, b.reference AS reference, b.booked_at AS happened_at,
                    CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)) AS actor,
                    CONCAT('Booked seat ', b.seat_number, ' on ', t.trip_code) AS detail
             FROM bookings b
             INNER JOIN users u ON u.id = b.passenger_id
             INNER JOIN trips t ON t.id = b.trip_id
             WHERE b.booked_at > (NOW() - INTERVAL 2 DAY)
             UNION ALL
             SELECT 'trip', t.trip_code, t.updated_at,
                    COALESCE(o.company_name, 'System'),
                    CONCAT('Trip status: ', t.status)
             FROM trips t LEFT JOIN operators o ON o.id = t.operator_id
             WHERE t.updated_at > (NOW() - INTERVAL 2 DAY)
             UNION ALL
             SELECT 'payment', p.reference, p.created_at,
                    CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)),
                    CONCAT(UPPER(p.status), ' ', FORMAT(p.amount,0), ' ', p.currency)
             FROM payments p INNER JOIN users u ON u.id = p.user_id
             WHERE p.created_at > (NOW() - INTERVAL 2 DAY)
             ORDER BY happened_at DESC
             LIMIT $limit"
        );
    }
}
