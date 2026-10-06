<?php
/**
 * UniGo - GPS / vehicle location service.
 *
 * Two sources feed the same table:
 *
 *  1. REAL hardware / phone GPS  -> POST /api/vehicles/location.php
 *     source = 'gps_device' | 'driver_phone', is_simulated = 0
 *
 *  2. DEMO simulator (this class) -> source = 'simulator', is_simulated = 1
 *     Positions are interpolated along the route polyline and are ALWAYS
 *     returned to the client with is_simulated = 1 so the UI can label them
 *     "SIMULATED". No part of the application pretends demo data is a live feed.
 *
 * The client polls /api/vehicles/location.php every N seconds via fetch().
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\ValidationException;

final class GpsService
{
    // ------------------------------------------------------------------
    // Recording
    // ------------------------------------------------------------------

    /**
     * Record a real position report. Authorisation is the caller's job.
     *
     * @return array{id:int,recorded_at:string}
     */
    public static function record(int $vehicleId, float $lat, float $lon, float $speed = 0, float $heading = 0, string $source = 'gps_device', ?int $tripId = null, float $accuracy = 10): array
    {
        if ($source === 'simulator' && !is_demo_mode()) throw new ValidationException('GPS simulation is disabled.');
        if (!\App\Core\Validator::isLatitude((string) $lat) || !\App\Core\Validator::isLongitude((string) $lon)) {
            throw new ValidationException('The coordinates received were not valid.');
        }
        if (!in_array($source, ['gps_device', 'driver_phone', 'simulator', 'manual'], true)) {
            $source = 'gps_device';
        }

        $db = Database::instance();
        $now = date('Y-m-d H:i:s');

        $id = $db->insert('vehicle_locations', [
            'vehicle_id'   => $vehicleId,
            'trip_id'      => $tripId,
            'latitude'     => $lat,
            'longitude'    => $lon,
            'speed'        => max(0, min(200, $speed)),
            'heading'      => fmod(max(0, $heading), 360),
            'accuracy'     => max(0, $accuracy),
            'recorded_at'  => $now,
            'source'       => $source,
            'is_simulated' => $source === 'simulator' ? 1 : 0,
        ]);

        // Keep only the recent trail to stop the table growing without bound.
        if (random_int(1, 200) === 1) {
            $db->run(
                'DELETE FROM vehicle_locations WHERE vehicle_id = ? AND recorded_at < (NOW() - INTERVAL 3 DAY)',
                [$vehicleId]
            );
        }

        return ['id' => $id, 'recorded_at' => $now];
    }

    /**
     * Record from the driver's phone (POST /api/driver/location.php).
     * Marks the row as NOT simulated because it came from a real device.
     */
    public static function recordFromDevice(int $vehicleId, float $lat, float $lon, float $speed, float $heading, float $accuracy, ?int $tripId): array
    {
        return self::record($vehicleId, $lat, $lon, $speed, $heading, 'driver_phone', $tripId, $accuracy);
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * Latest position per vehicle. One query, no N+1.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function latestPositions(?array $vehicleIds = null, int $limit = 200): array
    {
        if ($vehicleIds === []) return [];
        $limit = max(1, min(500, $limit));
        $realOnly = is_demo_mode() ? '' : ' AND is_simulated = 0 AND recorded_at >= (NOW() - INTERVAL 5 MINUTE)';
        $sql = "SELECT v.id AS vehicle_id, v.registration_number, v.vehicle_type, v.status,
                       vl.latitude, vl.longitude, vl.speed, vl.heading, vl.recorded_at,
                       vl.source, vl.is_simulated, vl.trip_id,
                       o.company_name
                FROM vehicles v
                LEFT JOIN vehicle_locations vl ON vl.id = (
                    SELECT id FROM vehicle_locations WHERE vehicle_id = v.id $realOnly
                    ORDER BY recorded_at DESC, id DESC LIMIT 1
                )
                LEFT JOIN operators o ON o.id = v.operator_id
                WHERE v.status IN ('active','on_trip') AND vl.id IS NOT NULL";
        $params = [];

        if ($vehicleIds) {
            $ids = array_map('intval', $vehicleIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND v.id IN ($placeholders)";
            $params = $ids;
        }
        $sql .= " ORDER BY vl.recorded_at DESC LIMIT $limit";

        return Database::instance()->select($sql, $params);
    }

    public static function latestForVehicle(int $vehicleId): ?array
    {
        $realOnly = is_demo_mode() ? '' : ' AND is_simulated = 0 AND recorded_at >= (NOW() - INTERVAL 5 MINUTE)';
        return Database::instance()->first(
            "SELECT * FROM vehicle_locations WHERE vehicle_id = ? $realOnly ORDER BY recorded_at DESC, id DESC LIMIT 1",
            [$vehicleId]
        );
    }

    /**
     * Movement trail for the tracking map.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function trail(int $vehicleId, int $minutes = 60, int $limit = 200): array
    {
        $realOnly = is_demo_mode() ? '' : ' AND is_simulated = 0 AND recorded_at >= (NOW() - INTERVAL 5 MINUTE)';
        $minutes = max(1, min(1440, $minutes));
        $limit = max(1, min(1000, $limit));
        return Database::instance()->select(
            "SELECT latitude, longitude, speed, heading, recorded_at, is_simulated
             FROM vehicle_locations
             WHERE vehicle_id = ? $realOnly AND recorded_at >= (NOW() - INTERVAL ? MINUTE)
             ORDER BY recorded_at ASC
             LIMIT $limit",
            [$vehicleId, $minutes]
        );
    }

    // ------------------------------------------------------------------
    // Demo simulator
    // ------------------------------------------------------------------

    /**
     * Advance every vehicle that is currently on a live trip by moving it a
     * few hundred metres along its route. Writes rows flagged is_simulated = 1.
     *
     * Run from CLI:  php scripts/simulate_gps.php
     * Or from the admin "Advance simulation" button.
     *
     * @return array{updated:int,timestamp:string,is_simulated:bool}
     */
    public static function simulateStep(?int $tripId = null): array
    {
        if (!is_demo_mode()) throw new ValidationException('GPS simulation is disabled.');
        $db = Database::instance();
        $trips = $tripId
            ? $db->select("SELECT * FROM trips WHERE id = ? AND status = 'in_transit'", [$tripId])
            : $db->select("SELECT * FROM trips WHERE status = 'in_transit'");

        $updated = 0;
        foreach ($trips as $trip) {
            $position = self::positionForTrip($trip);
            if ($position === null) {
                continue;
            }
            self::record(
                (int) $trip['vehicle_id'],
                $position['latitude'],
                $position['longitude'],
                $position['speed'],
                $position['heading'],
                'simulator',
                (int) $trip['id']
            );
            $updated++;
        }

        return ['updated' => $updated, 'timestamp' => date('c'), 'is_simulated' => true];
    }

    /**
     * Interpolate a trip's position from the wall clock, using its route
     * origin -> stops -> destination polyline.
     *
     * @return array{latitude:float,longitude:float,speed:float,heading:float}|null
     */
    public static function positionForTrip(array $trip): ?array
    {
        $routeId = (int) $trip['route_id'];
        $points = self::routePolyline($routeId);
        if (count($points) < 2) {
            return null;
        }

        // Progress 0..1 between actual departure (or scheduled) and arrival.
        $start = strtotime((string) ($trip['actual_departure'] ?: $trip['departure_time']));
        $end   = strtotime((string) ($trip['arrival_time'] ?: date('Y-m-d H:i:s', strtotime((string) $trip['departure_time']) + 3600)));
        if ($end <= $start) {
            $end = $start + 3600;
        }

        $progress = (time() - $start) / ($end - $start);
        $progress = max(0.0, min(1.0, $progress));

        $segment = $progress * (count($points) - 1);
        $index = min((int) floor($segment), count($points) - 2);
        $fraction = $segment - $index;

        $a = $points[$index];
        $b = $points[$index + 1];

        $lat = (float) $a['latitude'] + ((float) $b['latitude'] - (float) $a['latitude']) * $fraction;
        $lon = (float) $a['longitude'] + ((float) $b['longitude'] - (float) $a['longitude']) * $fraction;

        // Small deterministic wobble so the marker does not look robotic.
        $seed = crc32(($trip['trip_code'] ?? 'x') . (string) floor(time() / 60));
        $lat += (($seed % 1000) / 1000000) - 0.0005;
        $lon += ((($seed >> 10) % 1000) / 1000000) - 0.0005;

        $speed = 30 + (($seed % 25));           // 30-55 km/h city average

        // Bearing between the two points.
        $heading = self::bearing((float) $a['latitude'], (float) $a['longitude'], (float) $b['latitude'], (float) $b['longitude']);

        return [
            'latitude'  => round($lat, 7),
            'longitude' => round($lon, 7),
            'speed'     => $speed,
            'heading'   => $heading,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function routePolyline(int $routeId): array
    {
        $route = Database::instance()->first('SELECT * FROM routes WHERE id = ?', [$routeId]);
        if (!$route) {
            return [];
        }
        $stops = Database::instance()->select(
            'SELECT latitude, longitude FROM route_stops WHERE route_id = ? ORDER BY stop_order ASC',
            [$routeId]
        );

        $points = [[
            'latitude'  => (float) $route['origin_latitude'],
            'longitude' => (float) $route['origin_longitude'],
        ]];
        foreach ($stops as $s) {
            $points[] = ['latitude' => (float) $s['latitude'], 'longitude' => (float) $s['longitude']];
        }
        $points[] = [
            'latitude'  => (float) $route['destination_latitude'],
            'longitude' => (float) $route['destination_longitude'],
        ];

        return $points;
    }

    /** Great-circle initial bearing in degrees. */
    public static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $theta = deg2rad($lon2 - $lon1);
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);

        $y = sin($theta) * cos($phi2);
        $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($theta);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /** Distance in km (Haversine). */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /**
     * Estimated arrival time for a vehicle heading to a passenger.
     *
     * @return array{minutes:int,distance_km:float,is_simulated:bool}
     */
    public static function estimateArrival(int $vehicleId, float $destLat, float $destLon): array
    {
        $last = self::latestForVehicle($vehicleId);
        if (!$last) {
            return ['minutes' => -1, 'distance_km' => 0.0, 'is_simulated' => (bool) Config::get('domain.demo_mode', true)];
        }
        $distance = self::distanceKm((float) $last['latitude'], (float) $last['longitude'], $destLat, $destLon);
        $speed = max(8.0, (float) $last['speed']);           // floor at 8 km/h for city traffic
        $minutes = (int) ceil(($distance / $speed) * 60);

        return [
            'minutes'      => max(1, min(600, $minutes)),
            'distance_km'  => $distance,
            'is_simulated' => (bool) $last['is_simulated'],
        ];
    }

    /** Whether ANY vehicle position on the system is real hardware data. */
    public static function hasRealGpsFeed(): bool
    {
        return Database::instance()->exists(
            "SELECT 1 FROM vehicle_locations WHERE is_simulated = 0 AND recorded_at > (NOW() - INTERVAL 1 HOUR) LIMIT 1"
        );
    }
}
