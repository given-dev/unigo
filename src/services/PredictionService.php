<?php
/**
 * UniGo - Prediction service (DEMO HEURISTICS - NOT MACHINE LEARNING).
 *
 * Honesty note
 * ------------
 * The project brief mentions AI (demand prediction, congestion prediction,
 * route optimisation, predictive maintenance, fraud detection). This version
 * does NOT contain trained models. It contains clearly-labelled *heuristic*
 * estimators that run in plain SQL/PHP so the architecture, data flow and
 * dashboard integration can be evaluated.
 *
 * Every value produced here is written to `ai_predictions` with:
 *   model      = 'heuristic-v0'
 *   is_demo    = 1
 * and the UI renders a "DEMO" badge plus the method name, so nothing here can
 * be mistaken for a trained model.
 *
 * To make these real later: keep the same interface, replace the body with a
 * call to a model server (or run inference in a queue worker), and flip
 * is_demo to 0 once validated.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class PredictionService
{
    public const MODEL = 'heuristic-v0';

    /** Modules exposed in the architecture. */
    public const MODULES = [
        'demand'       => 'Passenger demand forecasting',
        'congestion'   => 'Traffic congestion estimation',
        'optimisation' => 'Route / dispatch optimisation',
        'maintenance'  => 'Predictive maintenance',
        'fraud'        => 'Payment fraud detection',
        'safety'       => 'Driver safety analysis',
    ];

    /**
     * Run every module and persist the output.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function runAll(): array
    {
        if (!\App\Core\Config::get('domain.demo_mode', false)) return [];
        $results = array_merge(
            self::demandForecast(),
            self::congestionForecast(),
            self::maintenanceForecast(),
            self::fraudSignals(),
            self::safetySignals(),
            self::dispatchSuggestions()
        );

        $db = Database::instance();
        $db->run('DELETE FROM ai_predictions WHERE is_demo = 1 AND generated_at < (NOW() - INTERVAL 1 HOUR)');
        foreach ($results as $r) {
            $db->insert('ai_predictions', [
                'module'        => $r['module'],
                'scope_type'    => $r['scope_type'],
                'scope_id'      => $r['scope_id'] ?? null,
                'label'         => mb_substr($r['label'], 0, 120),
                'score'         => $r['score'],
                'value_numeric' => $r['value_numeric'] ?? null,
                'value_text'    => mb_substr($r['value_text'] ?? '', 0, 255),
                'confidence'    => $r['confidence'],
                'model'         => self::MODEL,
                'is_demo'       => 1,
                'features'      => json_encode($r['features'] ?? []),
                'generated_at'  => date('Y-m-d H:i:s'),
                'expires_at'    => date('Y-m-d H:i:s', strtotime('+1 hour')),
            ]);
        }
        return $results;
    }

    public static function latest(string $module, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return Database::instance()->select(
            "SELECT * FROM ai_predictions
             WHERE module = ? AND generated_at > (NOW() - INTERVAL 2 HOUR)
             ORDER BY generated_at DESC LIMIT $limit",
            [$module]
        );
    }

    // ------------------------------------------------------------------
    // 1. Demand forecast (heuristic: historical load per route/hour)
    // ------------------------------------------------------------------
    public static function demandForecast(): array
    {
        $rows = Database::instance()->select(
            "SELECT r.id AS route_id, r.name,
                    COALESCE(SUM(b.id), 0) AS bookings,
                    COALESCE(AVG(b.seat_number + 0), 0) AS dummy
             FROM routes r
             LEFT JOIN trips t ON t.route_id = r.id
             LEFT JOIN bookings b ON b.trip_id = t.id AND b.status IN ('confirmed','completed')
                    AND t.departure_time >= (CURDATE() - INTERVAL 30 DAY)
             WHERE r.status = 'active'
             GROUP BY r.id
             HAVING bookings > 0
             ORDER BY bookings DESC
             LIMIT 6"
        );

        $out = [];
        $max = $rows ? (float) $rows[0]['bookings'] : 1.0;
        foreach ($rows as $row) {
            $load = (float) $row['bookings'] / max(1.0, $max);
            $out[] = [
                'module'        => 'demand',
                'scope_type'    => 'route',
                'scope_id'      => (int) $row['route_id'],
                'label'         => $row['name'],
                'score'         => round(min(1.0, $load), 4),
                'value_numeric' => (int) $row['bookings'],
                'value_text'    => $load > 0.75 ? 'High demand - consider adding a departure'
                            : ($load > 0.4 ? 'Moderate demand' : 'Low demand'),
                'confidence'    => 0.35 + round($load * 0.2, 2),
                'features'      => ['window_days' => 30, 'method' => 'historical booking volume, normalised'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 2. Congestion estimate (heuristic: delay minutes vs route duration)
    // ------------------------------------------------------------------
    public static function congestionForecast(): array
    {
        $rows = Database::instance()->select(
            "SELECT r.id AS route_id, r.name, r.duration_minutes,
                    COALESCE(AVG(NULLIF(t.delay_minutes,0)), 0) AS avg_delay,
                    COUNT(t.id) AS trips
             FROM routes r
             INNER JOIN trips t ON t.route_id = r.id
             WHERE t.departure_time >= (CURDATE() - INTERVAL 14 DAY)
             GROUP BY r.id
             HAVING trips >= 2
             ORDER BY avg_delay DESC
             LIMIT 6"
        );

        $out = [];
        foreach ($rows as $row) {
            $duration = max(10, (int) $row['duration_minutes']);
            $ratio = (float) $row['avg_delay'] / $duration;
            $score = min(1.0, $ratio);
            $out[] = [
                'module'        => 'congestion',
                'scope_type'    => 'route',
                'scope_id'      => (int) $row['route_id'],
                'label'         => $row['name'],
                'score'         => round($score, 4),
                'value_numeric' => (float) $row['avg_delay'],
                'value_text'    => $score > 0.4 ? 'Likely to run late - advise passengers'
                            : ($score > 0.15 ? 'Minor delays expected' : 'Running close to schedule'),
                'confidence'    => 0.3,
                'features'      => ['window_days' => 14, 'avg_delay_min' => (float) $row['avg_delay'], 'method' => 'observed delay / scheduled duration'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 3. Predictive maintenance (heuristic: odometer + inspection age)
    // ------------------------------------------------------------------
    public static function maintenanceForecast(): array
    {
        $rows = Database::instance()->select(
            "SELECT v.id, v.registration_number, v.odometer_km, v.last_inspection, v.inspection_status,
                    DATEDIFF(CURDATE(), COALESCE(v.last_inspection, '2020-01-01')) AS days_since_inspection
             FROM vehicles v
             WHERE v.status IN ('active','on_trip')
             ORDER BY days_since_inspection DESC
             LIMIT 6"
        );

        $out = [];
        foreach ($rows as $row) {
            $days = (int) $row['days_since_inspection'];
            $odometer = (float) $row['odometer_km'];
            $score = min(1.0, ($days / 180) * 0.6 + ($odometer / 250000) * 0.4);
            $out[] = [
                'module'        => 'maintenance',
                'scope_type'    => 'vehicle',
                'scope_id'      => (int) $row['id'],
                'label'         => $row['registration_number'],
                'score'         => round($score, 4),
                'value_numeric' => $days,
                'value_text'    => $score > 0.66 ? 'Schedule servicing now'
                            : ($score > 0.33 ? 'Service within 30 days' : 'Healthy'),
                'confidence'    => 0.4,
                'features'      => ['days_since_inspection' => $days, 'odometer_km' => $odometer, 'method' => 'age + odometer heuristic'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 4. Fraud signals (heuristic: refund / failure / cancel ratios)
    // ------------------------------------------------------------------
    public static function fraudSignals(): array
    {
        $rows = Database::instance()->select(
            "SELECT u.id, u.email, u.first_name, u.last_name,
                    COUNT(p.id) AS attempts,
                    SUM(p.status = 'failed')   AS failed,
                    SUM(p.status = 'refunded') AS refunded
             FROM users u
             INNER JOIN payments p ON p.user_id = u.id
             GROUP BY u.id
             HAVING attempts >= 2
             ORDER BY (failed + refunded) DESC
             LIMIT 5"
        );

        $out = [];
        foreach ($rows as $row) {
            $attempts = max(1, (int) $row['attempts']);
            $bad = (int) $row['failed'] + (int) $row['refunded'];
            $ratio = $bad / $attempts;
            if ($ratio < 0.34) {
                continue;
            }
            $out[] = [
                'module'        => 'fraud',
                'scope_type'    => 'user',
                'scope_id'      => (int) $row['id'],
                'label'         => trim($row['first_name'] . ' ' . $row['last_name']) . ' (' . $row['email'] . ')',
                'score'         => round(min(1.0, $ratio), 4),
                'value_numeric' => (int) $row['attempts'],
                'value_text'    => $bad . ' of ' . $attempts . ' transactions failed or were refunded',
                'confidence'    => 0.25,
                'features'      => ['failed' => (int) $row['failed'], 'refunded' => (int) $row['refunded'], 'method' => 'failure ratio rule'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 5. Driver safety signals (heuristic: speed + emergency incidents)
    // ------------------------------------------------------------------
    public static function safetySignals(): array
    {
        $rows = Database::instance()->select(
            "SELECT d.id, u.first_name, u.last_name, d.license_number,
                    COALESCE(MAX(vl.speed), 0) AS top_speed,
                    COALESCE((SELECT COUNT(*) FROM emergency_alerts ea
                              WHERE ea.driver_id = d.id OR ea.vehicle_id = v.id), 0) AS incidents
             FROM drivers d
             INNER JOIN users u ON u.id = d.user_id
             LEFT JOIN vehicles v ON v.driver_id = d.id
             LEFT JOIN vehicle_locations vl ON vl.vehicle_id = v.id
                    AND vl.recorded_at > (NOW() - INTERVAL 7 DAY)
             WHERE d.status <> 'suspended'
             GROUP BY d.id
             ORDER BY incidents DESC, top_speed DESC
             LIMIT 5"
        );

        $out = [];
        foreach ($rows as $row) {
            $speed = (float) $row['top_speed'];
            $incidents = (int) $row['incidents'];
            $score = min(1.0, max(0.0, ($speed - 60) / 60) * 0.6 + min(1.0, $incidents / 3) * 0.4);
            if ($score < 0.15) {
                continue;
            }
            $out[] = [
                'module'        => 'safety',
                'scope_type'    => 'driver',
                'scope_id'      => (int) $row['id'],
                'label'         => trim($row['first_name'] . ' ' . $row['last_name']),
                'score'         => round($score, 4),
                'value_numeric' => $speed,
                'value_text'    => $speed > 80 ? 'Speeding above 80 km/h observed' : 'Multiple emergency incidents reported',
                'confidence'    => 0.2,
                'features'      => ['top_speed_kmh' => $speed, 'incidents' => $incidents, 'method' => 'speed + incident heuristic'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 6. Dispatch suggestions (heuristic: seat load per scheduled trip)
    // ------------------------------------------------------------------
    public static function dispatchSuggestions(): array
    {
        $rows = Database::instance()->select(
            "SELECT t.id, t.trip_code, t.departure_time, t.seats_total,
                    (SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id AND b.status IN ('pending','confirmed')) AS booked,
                    r.name AS route_name
             FROM trips t
             INNER JOIN routes r ON r.id = t.route_id
             WHERE t.status = 'scheduled' AND t.departure_time BETWEEN NOW() AND (NOW() + INTERVAL 1 DAY)
             ORDER BY t.departure_time ASC
             LIMIT 8"
        );

        $out = [];
        foreach ($rows as $row) {
            $total = max(1, (int) $row['seats_total']);
            $load = (int) $row['booked'] / $total;
            if ($load < 0.2) {
                continue;
            }
            $out[] = [
                'module'        => 'optimisation',
                'scope_type'    => 'trip',
                'scope_id'      => (int) $row['id'],
                'label'         => $row['trip_code'] . ' - ' . $row['route_name'],
                'score'         => round($load, 4),
                'value_numeric' => (int) $row['booked'],
                'value_text'    => $load > 0.8 ? 'Nearly full - consider a second vehicle' : 'Low load - consider merging departures',
                'confidence'    => 0.5,
                'features'      => ['booked' => (int) $row['booked'], 'seats' => $total, 'method' => 'seat load ratio'],
            ];
        }
        return $out;
    }

    /** Badge metadata so every UI renders the same honesty disclaimer. */
    public static function disclosure(): array
    {
        return [
            'is_demo'  => true,
            'model'    => self::MODEL,
            'title'    => 'Heuristic estimates (DEMO)',
            'message'  => 'These values are produced by simple rules in PHP/SQL. No machine-learning model has been trained or deployed.',
        ];
    }
}
