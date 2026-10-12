<?php
/**
 * UniGo - "AI" route advisor (teaching demo).
 *
 * This is a deliberately tiny, fully explainable model called a *linear
 * weighted sum*. It is not machine learning magic: it turns each route's live
 * traffic numbers into a handful of features, multiplies each feature by a
 * weight, adds the results, and calls that number a "congestion cost". The
 * route with the lowest cost is the free-est route to drive.
 *
 *   cost = w1*open + w2*critical + w3*high + w4*avg_delay + w5*total_delay
 *
 * The weights start hand-tuned and can be nudged from human feedback (the
 * "Not helpful" / "Helpful" buttons), which is the simplest possible version
 * of training. In a real system you would replace the hand-picked features
 * and weights with a model fitted to historical trip data.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class RouteAdvisor
{
    /** Settings key that stores the tunable weights and feedback counter. */
    private const SETTINGS_KEY = 'ai_route_weights';

    /** Weights are kept inside these bounds so the toy model stays sane. */
    private const MIN_WEIGHT = 0.001;
    private const MAX_WEIGHT = 10.0;

    /**
     * The hand-tuned starting weights. These are the "model parameters".
     *
     * @var array<string,float>
     */
    public const DEFAULT_WEIGHTS = [
        'open'        => 1.5,
        'critical'    => 5.0,
        'high'        => 2.5,
        'avg_delay'   => 0.06,
        'total_delay' => 0.01,
    ];

    /** @return array<string,float> the current model weights */
    public function weights(): array
    {
        $raw = SettingsService::get(self::SETTINGS_KEY);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $weights = self::DEFAULT_WEIGHTS;
                foreach (self::DEFAULT_WEIGHTS as $key => $default) {
                    if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
                        $weights[$key] = $this->clamp((float) $decoded[$key]);
                    }
                }
                return $weights;
            }
        }
        return self::DEFAULT_WEIGHTS;
    }

    /** @param array<string,float> $weights */
    private function saveWeights(array $weights, int $feedbackDelta = 0): void
    {
        $stored = ['weights' => $weights, 'feedback' => max(0, $this->feedbackCount() + $feedbackDelta)];
        SettingsService::set(self::SETTINGS_KEY, json_encode($stored));
    }

    public function feedbackCount(): int
    {
        $raw = SettingsService::get(self::SETTINGS_KEY);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['feedback'])) {
                return (int) $decoded['feedback'];
            }
        }
        return 0;
    }

    /**
     * Every active route with its traffic features and computed congestion cost,
     * cheapest (free-est) first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rank(?string $district = null): array
    {
        $db = Database::instance();
        $weights = $this->weights();

        $sql = "SELECT r.id, r.route_code, r.name, r.origin_name, r.destination_name, r.operator_id,
                    COALESCE(SUM(t.status <> 'cleared'), 0)                        AS open_reports,
                    COALESCE(SUM(t.severity = 'critical' AND t.status <> 'cleared'), 0) AS critical_reports,
                    COALESCE(SUM(t.severity = 'high' AND t.status <> 'cleared'), 0)     AS high_reports,
                    COALESCE(AVG(CASE WHEN t.status <> 'cleared' THEN t.delay_minutes END), 0) AS avg_delay,
                    COALESCE(SUM(CASE WHEN t.status <> 'cleared' THEN t.delay_minutes ELSE 0 END), 0) AS total_delay
             FROM routes r
             LEFT JOIN traffic_reports t ON t.route_id = r.id
             WHERE r.status = 'active'";
        $params = [];
        if ($district !== null) {
            $sql .= " AND (r.origin_name = ? OR r.destination_name = ?)";
            $params = [$district, $district];
        }
        $sql .= " GROUP BY r.id ORDER BY r.name";

        $rows = $db->select($sql, $params);

        $ranked = [];
        foreach ($rows as $row) {
            $features = [
                'open'        => (float) $row['open_reports'],
                'critical'    => (float) $row['critical_reports'],
                'high'        => (float) $row['high_reports'],
                'avg_delay'   => (float) $row['avg_delay'],
                'total_delay' => (float) $row['total_delay'],
            ];
            $contributions = [];
            $cost = 0.0;
            foreach ($features as $key => $value) {
                $part = round($value * $weights[$key], 2);
                $contributions[$key] = $part;
                $cost += $part;
            }
            arsort($contributions);

            $ranked[] = [
                'route'         => $row,
                'features'      => $features,
                'contributions' => $contributions,
                'cost'          => round($cost, 2),
                'label'         => $this->label($cost),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
        return $ranked;
    }

    /**
     * The recommendation: the free-est route plus a plain-English reason and
     * the full ranking so the advice can be checked by a human.
     *
     * @return array<string,mixed>
     */
    public function advise(?string $district = null): array
    {
        $ranking = $this->rank($district);
        if (!$ranking) {
            return ['best' => null, 'ranking' => [], 'weights' => $this->weights(), 'feedback' => $this->feedbackCount(), 'reason' => 'No active routes to analyse yet.'];
        }

        $best = $ranking[0];
        $reason = $this->explain($best);
        return [
            'best'     => $best,
            'ranking'  => $ranking,
            'weights'  => $this->weights(),
            'feedback' => $this->feedbackCount(),
            'reason'   => $reason,
        ];
    }

    /** A one-paragraph explanation of why a route scored the way it did. */
    private function explain(array $entry): string
    {
        $features = $entry['features'];
        $route = $entry['route'];

        if ($entry['cost'] <= 0.0) {
            return $route['name'] . ' is clear: no open traffic reports are affecting it.';
        }
        $drivers = [];
        if ($features['critical'] > 0) {
            $drivers[] = (int) $features['critical'] . ' critical report(s)';
        }
        if ($features['high'] > 0) {
            $drivers[] = (int) $features['high'] . ' high-severity report(s)';
        }
        if ($features['avg_delay'] > 0) {
            $drivers[] = 'an average delay of ' . (int) round($features['avg_delay']) . ' min';
        }
        $why = $drivers ? 'mainly ' . implode(', ', array_slice($drivers, 0, 3)) : 'a small number of reports';
        return $route['name'] . ' is the free-est option right now (cost ' . $entry['cost'] . '), '
            . 'weighing ' . $why . '.';
    }

    /**
     * The simplest form of training: adjust the weights from a human verdict.
     * "Not helpful" sharpens the model's sensitivity to delays and severity;
     * "Helpful" softly relaxes it back. Weights are clamped so the toy model
     * stays sane.
     *
     * @return array<string,float> the updated weights
     */
    public function learn(bool $helpful): array
    {
        $weights = $this->weights();
        $factor = $helpful ? 0.97 : 1.08;

        // Free-est routes are sensitive to reopen cost; busy routes to delay.
        $weights['open']        = $this->clamp($weights['open'] * $factor);
        $weights['critical']    = $this->clamp($weights['critical'] * $factor);
        $weights['high']        = $this->clamp($weights['high'] * $factor);
        $weights['avg_delay']   = $this->clamp($weights['avg_delay'] * $factor);
        $weights['total_delay'] = $this->clamp($weights['total_delay'] * $factor);

        $this->saveWeights($weights, 1);
        return $weights;
    }

    private function clamp(float $value): float
    {
        return round(max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, $value)), 4);
    }

    /** Plain-language congestion label for a cost. */
    private function label(float $cost): string
    {
        return match (true) {
            $cost <= 0.0 => 'Clear',
            $cost < 3.0  => 'Light',
            $cost < 8.0  => 'Busy',
            default      => 'Congested',
        };
    }

    /** Colour used to draw the route on the map, by congestion label. */
    public static function colourForLabel(string $label): string
    {
        return match ($label) {
            'Clear'     => '#059669',
            'Light'     => '#65A30D',
            'Busy'      => '#D97706',
            'Congested' => '#DC2626',
            default     => '#2563EB',
        };
    }
}
