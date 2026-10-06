<?php
/**
 * UniGo - live trip tracking (passenger).
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Models\TripModel;
use App\Models\VehicleModel;

final class TrackingController extends Controller
{
    /** /tracking - active trips and a live (simulated) vehicle map. */
    public function index(): void
    {
        $this->requireLogin();
        $uid = (int) Auth::id();

        $trips = $this->safe(static fn () => (new TripModel())->forPassenger($uid, 10), []);

        $selectedId = $this->request->int('trip');
        if ($selectedId === 0 && $trips !== []) {
            $selectedId = (int) ($trips[0]['id'] ?? 0);
        }

        $allowed = array_map(static fn ($t) => (int) ($t['id'] ?? 0), $trips);
        $trip    = null;
        $map     = ['center' => [0.3476, 32.5825], 'zoom' => 12, 'markers' => [], 'vehicles' => [], 'route' => []];
        if ($selectedId > 0 && in_array($selectedId, $allowed, true)) {
            // Only ever show trips the passenger actually holds a booking on.
            $trip = $this->safe(static fn () => (new TripModel())->findDetailed($selectedId), null);
        }

        if ($trip) {
            $map = $this->buildMap($trip);
        }

        $this->view('tracking/index', [
            'title'      => 'Track a trip - ' . app_name(),
            'pageTitle'  => 'Live tracking',
            'pageSub'    => 'Vehicle positions are simulated in this demo',
            'trips'      => $trips,
            'trip'       => $trip,
            'mapConfig'  => $map,
            'withLeaflet' => true,
        ], 'layouts/app');
    }

    /** JSON feed restricted to a passenger's own active booking. */
    public function feed(string $id): void
    {
        $this->requireLogin();
        $allowed = \App\Core\Database::instance()->exists(
            "SELECT 1 FROM bookings WHERE trip_id = ? AND passenger_id = ? AND status IN ('pending','confirmed')",
            [(int) $id, (int) Auth::id()]
        );
        if (!$allowed) {
            \App\Core\Response::notFound('No active booking was found for that trip.')->send();
            return;
        }
        $trip = (new TripModel())->findDetailed((int) $id);
        if (!$trip) {
            \App\Core\Response::notFound()->send();
            return;
        }
        \App\Core\Response::success($this->buildMap($trip))->withHeader('Cache-Control', 'no-store')->send();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildMap(array $trip): array
    {
        $route   = [];
        $markers = [];
        $stops   = $trip['stops'] ?? [];

        foreach ($stops as $stop) {
            $lat = $stop['latitude'] ?? null;
            $lng = $stop['longitude'] ?? null;
            if ($lat === null || $lng === null) {
                continue;
            }
            $route[] = [(float) $lat, (float) $lng];
        }

        $hasStops = count($route) > 1;
        if (!$hasStops && isset($trip['origin_latitude'], $trip['origin_longitude'], $trip['destination_latitude'], $trip['destination_longitude'])) {
            $route = [
                [(float) $trip['origin_latitude'], (float) $trip['origin_longitude']],
                [(float) $trip['destination_latitude'], (float) $trip['destination_longitude']],
            ];
        }

        if (!empty($trip['origin_latitude'])) {
            $markers[] = ['lat' => (float) $trip['origin_latitude'], 'lng' => (float) $trip['origin_longitude'], 'type' => 'start', 'label' => (string) ($trip['origin_name'] ?? 'Origin')];
        }
        if (!empty($trip['destination_latitude'])) {
            $markers[] = ['lat' => (float) $trip['destination_latitude'], 'lng' => (float) $trip['destination_longitude'], 'type' => 'end', 'label' => (string) ($trip['destination_name'] ?? 'Destination')];
        }

        $vehicles = [];
        $vehicleId = (int) ($trip['vehicle_id'] ?? 0);
        $positions = $this->safe(static fn () => (new VehicleModel())->positionsForMap(200), []);
        foreach ($positions as $pos) {
            if ($vehicleId > 0 && (int) $pos['id'] !== $vehicleId) {
                continue;
            }
            if ($pos['latitude'] === null || $pos['longitude'] === null) {
                continue;
            }
            $vehicles[] = [
                'id'        => (int) $pos['id'],
                'lat'       => (float) $pos['latitude'],
                'lng'       => (float) $pos['longitude'],
                'label'     => (string) ($pos['registration_number'] ?? 'Vehicle'),
                'status'    => (string) ($pos['status'] ?? ''),
                'speed'     => isset($pos['speed']) ? (int) round((float) $pos['speed']) : null,
                'icon'      => transport_icon((string) ($pos['vehicle_type'] ?? 'bus')),
                'simulated' => (bool) ($pos['is_simulated'] ?? false),
                'moving'    => ((string) ($pos['status'] ?? '')) === 'on_trip',
            ];
        }

        $center = $route[0] ?? [0.3476, 32.5825];
        if ($vehicles !== []) {
            $center = [$vehicles[0]['lat'], $vehicles[0]['lng']];
        }

        return [
            'center'   => $center,
            'zoom'     => 12,
            'route'    => $route,
            'markers'  => $markers,
            'vehicles' => $vehicles,
            'follow'   => $vehicles !== [],
            'pollUrl'  => 'tracking/' . (int) $trip['id'],
        ];
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Tracking data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
