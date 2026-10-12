<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth, ActivityLog, Database, Flash, Paginator, Request, ValidationException, AuthorizationException, NotFoundException};
use App\Models\{TripModel, BookingModel, DeliveryModel, ComplaintModel, EmergencyModel, UserModel, VehicleModel, StatsModel, TrafficModel};
use App\Services\{GpsService, SettingsService, RouteAdvisor};

/** Staff records are explicitly scoped before any read or mutation. */
final class StaffController extends Controller
{
    private const RESOURCES = [
        'users' => ['users', 'id,email,first_name,last_name,phone,status,created_at'],
        'operators' => ['operators', 'id,company_name,license_number,contact_phone,approval_status,total_revenue'],
        'drivers' => ['drivers', 'id,user_id,license_number,operator_id,status,rating_avg,total_trips'],
        'vehicles' => ['vehicles', 'id,registration_number,vehicle_type,capacity,operator_id,driver_id,status,inspection_status'],
        'routes' => ['routes', 'id,route_code,name,origin_name,destination_name,base_fare,duration_minutes,status'],
        'trips' => ['trips', 'id,trip_code,route_id,vehicle_id,driver_id,departure_time,arrival_time,fare,status'],
        'bookings' => ['bookings', 'id,reference,trip_id,passenger_id,seat_number,status,payment_status,fare,boarded_at'],
        'deliveries' => ['deliveries', 'id,tracking_number,vehicle_id,recipient_name,recipient_phone,pickup_address,dropoff_address,status,price'],
        'payments' => ['payments', 'id,reference,user_id,booking_id,amount,method,status,is_mock,created_at'],
        'emergencies' => ['emergency_alerts', 'id,reference,trip_id,emergency_type,description,contact_phone,severity,status,resolution_note'],
        'sos' => ['emergency_alerts', 'id,reference,trip_id,emergency_type,description,contact_phone,severity,status'],
        'complaints' => ['complaints', 'id,reference,category,description,severity,status,resolution'],
        'ratings' => ['ratings', 'id,trip_id,driver_id,rating,comment,created_at'],
        'audit' => ['activity_logs', 'id,user_id,action,entity_type,entity_id,description,created_at'],
    ];

    private function workspace(): array
    {
        $path = $this->request->path();
        $entry = StubController::PAGES[$path] ?? null;
        if (!$entry) {
            throw new NotFoundException('Workspace not found.');
        }
        $this->requireRole($entry[2], 'admin');
        return [$entry[2], basename($path), $entry];
    }

    /** Returns SQL written only from our resource and role allowlists. */
    private function scope(string $role, string $resource): array
    {
        if ($role === 'admin') return ['1', []];
        if ($role === 'authority') return $this->authorityScope($resource);
        if ($role === 'operator') {
            $id = Auth::operatorId();
            if (!$id) throw new AuthorizationException('An operator profile is required.');
            return match ($resource) {
                'vehicles', 'drivers', 'routes', 'trips', 'deliveries' => ['operator_id = ?', [$id]],
                'bookings' => ['trip_id IN (SELECT id FROM trips WHERE operator_id = ?)', [$id]],
                default => ['0', []],
            };
        }
        $id = Auth::driverId();
        if (!$id) throw new AuthorizationException('A driver profile is required.');
        return match ($resource) {
            'trips', 'ratings' => ['driver_id = ?', [$id]],
            'bookings', 'sos' => ['trip_id IN (SELECT id FROM trips WHERE driver_id = ?)', [$id]],
            'deliveries' => ["trip_id IN (SELECT id FROM trips WHERE driver_id = ?) OR vehicle_id IN (SELECT vehicle_id FROM trips WHERE driver_id = ? AND status IN ('boarding','in_transit')) OR vehicle_id IN (SELECT id FROM vehicles WHERE driver_id = ?)", [$id,$id,$id]],
            default => ['0', []],
        };
    }

    /**
     * A regulator (authority) with no district is the national office and sees
     * everything. A district officer only sees routes that start or end in
     * their district, plus the operators, trips, vehicles and reports tied to
     * those routes.
     */
    private function authorityScope(string $resource): array
    {
        $district = Auth::authorityDistrict();
        if ($district === null) {
            return ['1', []];
        }
        $routeIds = '(SELECT id FROM routes WHERE origin_name = ? OR destination_name = ?)';
        $pair = [$district, $district];
        return match ($resource) {
            'routes'    => ['(origin_name = ? OR destination_name = ?)', $pair],
            'operators' => ["id IN (SELECT operator_id FROM routes WHERE operator_id IS NOT NULL AND (origin_name = ? OR destination_name = ?))", $pair],
            'trips'     => ["route_id IN $routeIds", $pair],
            'vehicles'  => ["(home_route_id IN $routeIds OR id IN (SELECT vehicle_id FROM trips WHERE route_id IN $routeIds))", array_merge($pair, $pair)],
            'drivers'   => ["id IN (SELECT driver_id FROM trips WHERE route_id IN $routeIds AND driver_id IS NOT NULL)", $pair],
            'bookings', 'complaints', 'emergencies', 'sos', 'deliveries', 'ratings'
                        => ["trip_id IN (SELECT id FROM trips WHERE route_id IN $routeIds)", $pair],
            default     => ['1', []],
        };
    }

    public function index(): void
    {
        [$role, $resource, $entry] = $this->workspace();
        $db = Database::instance();
        $rows = []; $columns = []; $paginator = null; $map = null; $report = []; $photos = []; $analytics = []; $traffic = []; $summary = []; $choices = []; $board = [];
        $national = $role === 'authority' && Auth::authorityDistrict() === null;
        if ($resource === 'trips' && in_array($role, ['operator', 'admin'], true)) {
            [$where, $params] = $this->scope($role, 'trips');
            $where = str_replace('operator_id', 't.operator_id', $where);
            $term = $this->request->str('search');
            if ($term !== '') {
                $like = '%' . $term . '%';
                $where = "($where) AND (t.trip_code LIKE ? OR r.name LIKE ? OR v.registration_number LIKE ? OR CONCAT(COALESCE(du.first_name,''),' ',COALESCE(du.last_name,'')) LIKE ?)";
                array_push($params, $like, $like, $like, $like);
            }
            $joins = 'trips t INNER JOIN routes r ON r.id = t.route_id INNER JOIN vehicles v ON v.id = t.vehicle_id LEFT JOIN drivers d ON d.id = t.driver_id LEFT JOIN users du ON du.id = d.user_id';
            $total = $db->count("SELECT COUNT(*) FROM $joins WHERE $where", $params);
            $paginator = new Paginator($total, $this->request->int('page', 1), 20);
            $rows = array_map(
                static function (array $r): array {
                    $r['departure_time'] = date('M j · H:i', strtotime((string) $r['departure_time']));
                    $r['seats_booked'] = (int) $r['seats_booked'] . ' / ' . (int) $r['seats_total'];
                    $r['fare'] = money((float) $r['fare']);
                    return $r;
                },
                $db->select(
                    "SELECT t.id, t.trip_code, r.name AS route_name,
                            CONCAT(v.registration_number, ' · ', REPLACE(v.vehicle_type, '_', ' ')) AS vehicle,
                            TRIM(CONCAT(COALESCE(du.first_name,''), ' ', COALESCE(du.last_name,''))) AS driver,
                            t.departure_time,
                            (SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id AND b.status IN ('pending','confirmed','completed')) AS seats_booked,
                            t.seats_total, t.fare, t.status
                     FROM $joins WHERE $where ORDER BY t.departure_time DESC LIMIT " . $paginator->limit() . ' OFFSET ' . $paginator->offset(),
                    $params
                )
            );
            $columns = ['trip_code', 'route_name', 'vehicle', 'driver', 'departure_time', 'seats_booked', 'fare', 'status'];
            if ($role === 'operator') {
                $opId = (int) Auth::operatorId();
                $board = [
                    'today'     => $db->count("SELECT COUNT(*) FROM trips WHERE operator_id = ? AND DATE(departure_time) = CURDATE()", [$opId]),
                    'boarding'  => $db->count("SELECT COUNT(*) FROM trips WHERE operator_id = ? AND status = 'boarding'", [$opId]),
                    'collected' => (float) $db->value(
                        "SELECT COALESCE(SUM(p.amount),0) FROM payments p
                         INNER JOIN bookings b ON b.id = p.booking_id
                         INNER JOIN trips t ON t.id = b.trip_id
                         WHERE t.operator_id = ? AND p.status = 'successful' AND DATE(p.completed_at) = CURDATE()", [$opId]),
                    'cash'      => (float) $db->value(
                        "SELECT COALESCE(SUM(p.amount),0) FROM payments p
                         INNER JOIN bookings b ON b.id = p.booking_id
                         INNER JOIN trips t ON t.id = b.trip_id
                         WHERE t.operator_id = ? AND p.status = 'successful' AND p.method = 'cash' AND DATE(p.completed_at) = CURDATE()", [$opId]),
                ];
            }
        } elseif (isset(self::RESOURCES[$resource])) {
            [$table, $select] = self::RESOURCES[$resource];
            if ($national && in_array($resource, ['emergencies', 'complaints', 'operators'], true)) {
                $summary = $this->nationalSummary($resource);
            } else {
                [$where, $params] = $this->scope($role, $resource);
                $term = $this->request->str('search');
                if ($term !== '') {
                    $conditions = [];
                    foreach (explode(',', $select) as $column) { $conditions[] = "CAST(`$column` AS CHAR) LIKE ?"; $params[] = '%' . $term . '%'; }
                    $where = '(' . $where . ')';
                    $where .= ' AND (' . implode(' OR ', $conditions) . ')';
                }
                $total = $db->count("SELECT COUNT(*) FROM `$table` WHERE $where", $params);
                $paginator = new Paginator($total, $this->request->int('page', 1), 20);
                $rows = $db->select("SELECT $select FROM `$table` WHERE $where ORDER BY id DESC LIMIT " . $paginator->limit() . ' OFFSET ' . $paginator->offset(), $params);
                $columns = explode(',', $select);
                if ($resource === 'vehicles' && $rows) {
                    $photos = (new VehicleModel())->photosFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));
                }
            }
        } elseif (in_array($resource, ['monitor', 'tracking'], true)) {
            $district = $role === 'authority' ? Auth::authorityDistrict() : null;
            $districtRouteIds = '(SELECT id FROM routes WHERE origin_name = ? OR destination_name = ?)';
            $ids = null;
            if ($role === 'driver') {
                $ids = array_column($db->select('SELECT id FROM vehicles WHERE driver_id = ? OR id IN (SELECT vehicle_id FROM trips WHERE driver_id = ?)', [(int) Auth::driverId(), (int) Auth::driverId()]), 'id');
                if (!$ids) $ids = [0];
            } elseif ($role === 'operator') {
                $opId = (int) Auth::operatorId();
                $ids = array_column($db->select('SELECT id FROM vehicles WHERE operator_id = ? OR id IN (SELECT vehicle_id FROM trips WHERE operator_id = ?)', [$opId, $opId]), 'id');
                if (!$ids) $ids = [0];
            } elseif ($district !== null) {
                $ids = array_column($db->select(
                    "SELECT DISTINCT vehicle_id FROM trips WHERE status IN ('scheduled','boarding','in_transit') AND route_id IN $districtRouteIds",
                    [$district, $district]
                ), 'vehicle_id');
                if (!$ids) $ids = [0];
            }
            $positions = GpsService::latestPositions($ids);

            // Draw the path each vehicle is travelling and name the route on
            // every marker so the network map shows routes, not just dots.
            $tripSql = "SELECT DISTINCT vehicle_id, route_id FROM trips WHERE status IN ('scheduled','boarding','in_transit')";
            $tripParams = [];
            if ($district !== null) {
                $tripSql .= " AND route_id IN $districtRouteIds";
                $tripParams = [$district, $district];
            } elseif ($role === 'operator') {
                $tripSql .= " AND operator_id = ?";
                $tripParams = [(int) Auth::operatorId()];
            }
            $vehicleRoute = [];
            foreach ($db->select($tripSql, $tripParams) as $t) {
                $vehicleRoute[(int) $t['vehicle_id']] = (int) $t['route_id'];
            }
            $routeNames = [];
            $routes = [];
            $routeIds = array_values(array_unique(array_filter($vehicleRoute)));
            if ($routeIds) {
                $placeholders = implode(',', array_fill(0, count($routeIds), '?'));
                foreach ($db->select("SELECT id, name, colour FROM routes WHERE id IN ($placeholders)", $routeIds) as $r) {
                    $routeNames[(int) $r['id']] = $r['name'];
                    $points = GpsService::routePolyline((int) $r['id']);
                    if (count($points) < 2) {
                        continue;
                    }
                    $routes[] = [
                        'label'  => $r['name'],
                        'color'  => $r['colour'] ?: '#2563EB',
                        'points' => array_map(static fn ($p) => [(float) $p['latitude'], (float) $p['longitude']], $points),
                    ];
                }
            }

            $map = [
                'center' => [0.3476, 32.5825],
                'zoom'   => 12,
                'routes' => $routes,
                'vehicles' => array_map(static function ($p) use ($vehicleRoute, $routeNames): array {
                    $routeId = $vehicleRoute[(int) $p['vehicle_id']] ?? null;
                    return [
                        'id' => (int) $p['vehicle_id'], 'lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude'],
                        'label' => $p['registration_number'], 'status' => $p['status'], 'simulated' => (bool) $p['is_simulated'],
                        'route' => $routeId !== null ? ($routeNames[$routeId] ?? null) : null,
                    ];
                }, $positions),
            ];
        } elseif ($resource === 'settings') {
            if ($role === 'admin') {
                $rows = $db->select('SELECT setting_key,setting_value,label FROM system_settings ORDER BY group_name,setting_key');
                $columns = ['setting_key','setting_value','label'];
            } else {
                $rows = $db->select('SELECT company_name,contact_email,contact_phone,address,description FROM operators WHERE id = ?', [(int) Auth::operatorId()]);
                $columns = ['company_name','contact_email','contact_phone','address','description'];
            }
        } elseif ($resource === 'traffic') {
            $traffic = $this->traffic();
            [$routeWhere, $routeParams] = $this->scope($role, 'routes');
            $choices['routes'] = $db->select("SELECT id, CONCAT(name, ' (', route_code, ')') AS label FROM routes WHERE status = 'active' AND ($routeWhere) ORDER BY name LIMIT 500", $routeParams);
        } elseif ($resource === 'analytics') {
            $analytics = $this->analytics();
        } else {
            $report = $this->report($role);
        }
        if (in_array($resource, ['trips','vehicles','routes','deliveries','drivers'], true) && in_array($role, ['admin','operator'], true)) {
            foreach (['vehicles' => 'registration_number', 'routes' => 'name', 'drivers' => 'license_number'] as $table => $label) {
                [$where,$params] = $this->scope($role, $table);
                $choices[$table] = $db->select("SELECT id, `$label` AS label FROM `$table` WHERE $where ORDER BY id LIMIT 500", $params);
            }
            if ($role === 'admin') $choices['operators'] = $db->select('SELECT id,company_name AS label FROM operators ORDER BY company_name LIMIT 500');
        }
        $manifest = [];
        if ($resource === 'trips' && $this->request->int('trip') > 0) {
            [$where,$params] = $this->scope($role, 'trips');
            $id = $this->request->int('trip');
            if (!$db->exists("SELECT 1 FROM trips WHERE id = ? AND ($where)", array_merge([$id],$params))) throw new AuthorizationException('This trip is outside your workspace.');
            $manifest = (new BookingModel())->passengersForTrip($id);
        }
        $pageSub = $entry[1];
        if ($role === 'authority') {
            $district = Auth::authorityDistrict();
            $pageSub .= $district === null ? ' - National office' : ' - District: ' . $district;
        }
        $this->view('staff/workspace', compact('role','resource','rows','columns','paginator','choices','map','report','manifest','photos','analytics','traffic','summary','board') + [
            'title' => $entry[0] . ' - UniGo', 'pageTitle' => $entry[0], 'pageSub' => $pageSub,
            'withLeaflet' => $map !== null || !empty($traffic['map']), 'withChart' => $analytics !== [],
        ], 'layouts/app');
    }

    /** Network performance analytics for the authority workspace. */
    private function analytics(): array
    {
        $district = Auth::authorityDistrict();
        $stats = new StatsModel();
        $fare = $stats->fareByRoute(10, $district);
        return [
            'top_routes'  => $stats->topRoutes(8, $district),
            'operators'   => $stats->operatorPerformance(8, $district),
            'drivers'     => $stats->topDrivers(8, $district),
            'transport'   => $stats->transportTypeMix($district),
            'trip_status' => $stats->tripStatusMix($district),
            'areas'       => $stats->tripsByArea(8, $district),
            'fare'        => $fare,
            'fare_totals' => [
                'collected'  => array_sum(array_map(static fn ($r) => (float) $r['collected'], $fare)),
                'bookings'   => array_sum(array_map(static fn ($r) => (int) $r['bookings'], $fare)),
                'overcharge' => array_sum(array_map(static fn ($r) => (int) $r['overcharge_complaints'], $fare)),
            ],
        ];
    }

    /**
     * National rollups. A regulator's national office works on summaries (who
     * has how many incidents, complaints, operators - by area, category and
     * company), while district officers handle the individual records. See
     * staff/rollup.php for the renderer.
     */
    private function nationalSummary(string $resource): array
    {
        $db = Database::instance();
        return match ($resource) {
            'emergencies' => $this->nationalEmergencies($db),
            'complaints'  => $this->nationalComplaints($db),
            'operators'   => $this->nationalOperators($db),
            default       => [],
        };
    }

    private function nationalEmergencies(Database $db): array
    {
        $open = "ea.status NOT IN ('resolved','dismissed')";
        return [
            'resource' => 'emergencies',
            'title'    => 'Alerts by area',
            'note'     => 'A national regulator tracks totals by area and severity; district officers work the individual alerts in their own workspace.',
            'tiles'    => [
                ['Total alerts', $db->count('SELECT COUNT(*) FROM emergency_alerts'), 'siren', 'primary'],
                ['Open alerts', $db->count("SELECT COUNT(*) FROM emergency_alerts ea WHERE $open"), 'alert', 'danger'],
                ['Critical', $db->count("SELECT COUNT(*) FROM emergency_alerts WHERE severity = 'critical'"), 'flame', 'danger'],
            ],
            'cols'  => ['area' => 'Area (route origin)', 'total' => 'Alerts', 'open' => 'Open', 'critical' => 'Critical'],
            'right' => ['total', 'open', 'critical'],
            'rows'  => array_map(static fn ($r): array => [
                'area' => (string) $r['area'], 'total' => (int) $r['total'], 'open' => (int) $r['open'], 'critical' => (int) $r['critical'],
            ], $db->select(
                "SELECT COALESCE(r.origin_name, 'Unassigned') AS area,
                        COUNT(ea.id) AS total,
                        SUM($open) AS open,
                        SUM(ea.severity = 'critical') AS critical
                 FROM emergency_alerts ea
                 LEFT JOIN trips t ON t.id = ea.trip_id
                 LEFT JOIN routes r ON r.id = t.route_id
                 GROUP BY r.origin_name
                 ORDER BY critical DESC, total DESC"
            )),
        ];
    }

    private function nationalComplaints(Database $db): array
    {
        $open = "c.status NOT IN ('resolved','dismissed')";
        return [
            'resource' => 'complaints',
            'title'    => 'Complaints by category',
            'note'     => 'Categories follow the regulated public complaint types; district officers resolve the individual complaints.',
            'tiles'    => [
                ['Total complaints', $db->count('SELECT COUNT(*) FROM complaints'), 'message', 'primary'],
                ['Open', $db->count("SELECT COUNT(*) FROM complaints c WHERE $open"), 'alert', 'warning'],
                ['Overcharging (open)', $db->count("SELECT COUNT(*) FROM complaints c WHERE c.category = 'overcharging' AND $open"), 'coins', 'danger'],
            ],
            'cols'  => ['category' => 'Category', 'total' => 'Total', 'open' => 'Open'],
            'right' => ['total', 'open'],
            'rows'  => array_map(static fn ($r): array => [
                'category' => ucwords(str_replace('_', ' ', (string) $r['category'])),
                'total' => (int) $r['total'], 'open' => (int) $r['open'],
            ], $db->select(
                "SELECT c.category, COUNT(*) AS total, SUM($open) AS open
                 FROM complaints c
                 GROUP BY c.category
                 ORDER BY total DESC"
            )),
        ];
    }

    private function nationalOperators(Database $db): array
    {
        return [
            'resource' => 'operators',
            'title'    => 'Operators at a glance',
            'note'     => 'Fleet, activity and collected fare per licensed operator. District officers manage the individual operator files.',
            'tiles'    => [
                ['Approved', $db->count("SELECT COUNT(*) FROM operators WHERE approval_status = 'approved'"), 'check', 'success'],
                ['Pending', $db->count("SELECT COUNT(*) FROM operators WHERE approval_status = 'pending'"), 'clock', 'warning'],
                ['Suspended', $db->count("SELECT COUNT(*) FROM operators WHERE approval_status = 'suspended'"), 'shield', 'danger'],
            ],
            'cols'  => ['company' => 'Operator', 'status' => 'Status', 'vehicles' => 'Vehicles', 'drivers' => 'Drivers', 'trips' => 'Trips', 'bookings' => 'Bookings', 'revenue' => 'Fare collected', 'rating' => 'Rating'],
            'right' => ['vehicles', 'drivers', 'trips', 'bookings', 'revenue', 'rating'],
            'rows'  => array_map(static fn ($r): array => [
                'company' => (string) $r['company_name'],
                'status'  => ucfirst((string) $r['approval_status']),
                'vehicles' => (int) $r['vehicles'],
                'drivers'  => (int) $r['drivers'],
                'trips'    => (int) $r['trips'],
                'bookings' => (int) $r['bookings'],
                'revenue'  => 'UGX ' . number_format((float) $r['revenue']),
                'rating'   => (float) $r['rating_avg'] > 0 ? number_format((float) $r['rating_avg'], 1) : '-',
            ], $db->select(
                "SELECT o.company_name, o.approval_status, o.rating_avg,
                        (SELECT COUNT(*) FROM vehicles v WHERE v.operator_id = o.id) AS vehicles,
                        (SELECT COUNT(*) FROM drivers d WHERE d.operator_id = o.id) AS drivers,
                        COUNT(DISTINCT t.id) AS trips,
                        COUNT(b.id) AS bookings,
                        COALESCE(SUM(CASE WHEN b.payment_status = 'paid' AND b.status <> 'cancelled' THEN b.fare ELSE 0 END),0) AS revenue
                 FROM operators o
                 LEFT JOIN trips t ON t.operator_id = o.id
                 LEFT JOIN bookings b ON b.trip_id = t.id
                 GROUP BY o.id
                 ORDER BY revenue DESC"
            )),
        ];
    }

    /** Traffic / congestion feed plus suggested diversion routes. */
    private function traffic(): array
    {
        $district = Auth::authorityDistrict();
        $stats = new StatsModel();
        $reports = $stats->trafficSnapshot(30, $district);
        $advice = (new RouteAdvisor())->advise($district);
        $map = $this->trafficMap($advice['ranking'] ?? [], $reports);
        $map['vehicles'] = $this->liveVehicleMarkers($district);
        return [
            'reports'     => $reports,
            'congestion'  => $stats->congestionByRoute(8, $district),
            'diversions'  => (new TrafficModel())->suggestForOpenReports($reports),
            'ai'          => $advice,
            'map'         => $map,
        ];
    }

    /** Vehicle markers for the network map (all vehicles, or the district's). */
    private function liveVehicleMarkers(?string $district): array
    {
        $db = Database::instance();
        $filter = '';
        $params = [];
        if ($district !== null) {
            $filter = " AND route_id IN (SELECT id FROM routes WHERE origin_name = ? OR destination_name = ?)";
            $params = [$district, $district];
        }
        $ids = array_column($db->select(
            "SELECT DISTINCT vehicle_id FROM trips WHERE status IN ('scheduled','boarding','in_transit')$filter", $params
        ), 'vehicle_id');
        if (!$ids) {
            return [];
        }
        $positions = GpsService::latestPositions($ids);

        $vehicleRoute = [];
        foreach ($db->select(
            "SELECT DISTINCT vehicle_id, route_id FROM trips WHERE status IN ('scheduled','boarding','in_transit')$filter", $params
        ) as $t) {
            $vehicleRoute[(int) $t['vehicle_id']] = (int) $t['route_id'];
        }
        $routeIds = array_values(array_unique(array_filter($vehicleRoute)));
        $routeNames = [];
        if ($routeIds) {
            $placeholders = implode(',', array_fill(0, count($routeIds), '?'));
            foreach ($db->select("SELECT id, name FROM routes WHERE id IN ($placeholders)", $routeIds) as $r) {
                $routeNames[(int) $r['id']] = $r['name'];
            }
        }
        return array_map(static function ($p) use ($vehicleRoute, $routeNames): array {
            $routeId = $vehicleRoute[(int) $p['vehicle_id']] ?? null;
            return [
                'id' => (int) $p['vehicle_id'], 'lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude'],
                'label' => $p['registration_number'], 'status' => $p['status'], 'simulated' => (bool) $p['is_simulated'],
                'route' => $routeId !== null ? ($routeNames[$routeId] ?? null) : null,
            ];
        }, $positions);
    }

    /**
     * Build the network map for the traffic page: each active route drawn in
     * the colour of its AI congestion grade, with a pin on every report.
     *
     * @param array<int,array<string,mixed>> $ranking
     * @param array<int,array<string,mixed>> $reports
     * @return array<string,mixed>
     */
    private function trafficMap(array $ranking, array $reports): array
    {
        $routes = [];
        foreach (array_slice($ranking, 0, 15) as $entry) {
            $points = GpsService::routePolyline((int) $entry['route']['id']);
            if (count($points) < 2) {
                continue;
            }
            $routes[] = [
                'label'  => $entry['route']['name'] . ' - ' . $entry['label'],
                'color'  => RouteAdvisor::colourForLabel($entry['label']),
                'weight' => $entry['label'] === 'Clear' ? 3 : 5,
                'points' => array_map(static fn ($p) => [(float) $p['latitude'], (float) $p['longitude']], $points),
            ];
        }

        $markers = [];
        foreach ($reports as $r) {
            if ($r['latitude'] === null || $r['longitude'] === null) {
                continue;
            }
            $where = (string) ($r['location_name'] ?: $r['route_name'] ?: 'Report');
            $markers[] = [
                'lat'   => (float) $r['latitude'],
                'lng'   => (float) $r['longitude'],
                'type'  => 'dest',
                'label' => $where,
                'popup' => '<b>' . htmlspecialchars($where) . '</b><br>'
                    . htmlspecialchars(ucfirst((string) $r['severity'])) . ' &middot; ' . (int) $r['delay_minutes'] . ' min delay',
            ];
        }

        return ['center' => [0.3476, 32.5825], 'zoom' => 11, 'routes' => $routes, 'markers' => $markers];
    }

    private function saveTraffic(string $role): void
    {
        if (!in_array($role, ['authority', 'admin'], true)) {
            throw new AuthorizationException('Only the authority can manage traffic reports.');
        }
        $db = Database::instance();
        $model = new TrafficModel();
        $action = $this->request->str('action');

        $district = $role === 'authority' ? Auth::authorityDistrict() : null;
        $inDistrict = static function (array $routeRow) use ($district): bool {
            if ($district === null) {
                return true;
            }
            return ($routeRow['origin_name'] ?? null) === $district
                || ($routeRow['destination_name'] ?? null) === $district;
        };

        if ($action === 'ai_feedback') {
            $helpful = $this->request->str('helpful') === '1';
            (new RouteAdvisor())->learn($helpful);
            ActivityLog::record('staff.updated', 0, 'traffic', 'AI route advisor feedback: ' . ($helpful ? 'helpful' : 'not helpful'));
            Flash::success('Thanks - the route advisor nudged its weights.');
            return;
        }

        if ($action === 'create') {
            $routeId = $this->request->int('route_id') ?: null;
            $location = substr($this->request->str('location_name'), 0, 150);
            if ($routeId === null && $location === '') {
                throw new ValidationException('Choose a route or enter a location.');
            }
            $route = $routeId ? $db->first('SELECT * FROM routes WHERE id = ?', [$routeId]) : null;
            if ($routeId !== null && $route === null) {
                throw new ValidationException('Choose an existing route.');
            }
            if ($route !== null && !$inDistrict($route)) {
                throw new AuthorizationException('This route is outside your district.');
            }
            $severity = $this->request->str('severity', 'medium');
            $id = $model->createReport([
                'route_id'      => $routeId,
                'location_name' => $location !== '' ? $location : (string) ($route['name'] ?? ''),
                'latitude'      => $route['origin_latitude'] ?? null,
                'longitude'     => $route['origin_longitude'] ?? null,
                'category'      => $this->request->str('category', 'congestion'),
                'severity'      => $severity,
                'delay_minutes' => $this->request->int('delay_minutes'),
                'description'   => $this->request->str('description'),
            ], (int) Auth::id());
            ActivityLog::record('staff.created', $id, 'traffic', 'Logged a ' . $severity . ' traffic report');
            if ($route && in_array($severity, ['high', 'critical'], true)) {
                $alternates = $model->alternatesForRoute($route, 3);
                $sent = $alternates ? $model->notifyDiversion($route, $alternates, (int) Auth::id()) : 0;
                Flash::success('Traffic report logged.' . ($sent > 0 ? ' ' . $sent . ' affected user(s) were offered an alternate route.' : ''));
            } else {
                Flash::success('Traffic report logged.');
            }
            return;
        }

        $id = $this->request->int('id');
        $report = $db->first('SELECT * FROM traffic_reports WHERE id = ?', [$id]);
        if (!$report) {
            throw new NotFoundException('Traffic report not found.');
        }
        if ($district !== null) {
            $routeReport = $report['route_id'] ? $db->first('SELECT id, origin_name, destination_name FROM routes WHERE id = ?', [(int) $report['route_id']]) : null;
            if (!$routeReport || !$inDistrict($routeReport)) {
                throw new AuthorizationException('This traffic report is outside your district.');
            }
        }
        if ($action === 'status') {
            $status = $this->request->str('status');
            $model->updateStatus($id, $status, (int) Auth::id());
            ActivityLog::record('staff.updated', $id, 'traffic', 'Traffic report moved to ' . $status);
            Flash::success('Traffic report updated.');
        } elseif ($action === 'diversion') {
            $route = $report['route_id'] ? $db->first('SELECT * FROM routes WHERE id = ?', [(int) $report['route_id']]) : null;
            if (!$route) {
                throw new ValidationException('This report is not linked to a route, so no diversion can be issued.');
            }
            $alternates = $model->alternatesForRoute($route, 3);
            if (!$alternates) {
                throw new ValidationException('No alternate active route is available for this route.');
            }
            $sent = $model->notifyDiversion($route, $alternates, (int) Auth::id());
            ActivityLog::record('staff.updated', $id, 'traffic', 'Issued a diversion for ' . $route['route_code']);
            Flash::success($sent > 0 ? 'Diversion issued. ' . $sent . ' affected user(s) were notified.' : 'Diversion route is on record, but nobody is currently booked on this route.');
        } else {
            throw new ValidationException('No traffic action is available here.');
        }
    }

    private function report(string $role): array
    {
        $db = Database::instance();
        [$where,$params] = $this->scope($role, 'trips');
        $trips = $db->select("SELECT status,COUNT(*) AS total FROM trips WHERE $where GROUP BY status", $params);
        [$bw,$bp] = $this->scope($role, 'bookings');
        $revenue = $db->first("SELECT COUNT(*) AS bookings,COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN fare ELSE 0 END),0) AS collected_fares FROM bookings WHERE $bw", $bp);
        return ['trips' => $trips, 'bookings' => $revenue['bookings'] ?? 0, 'collected_fares' => $revenue['collected_fares'] ?? 0];
    }

    public function save(): void
    {
        [$role,$resource] = $this->workspace();
        $this->verifyCsrf();
        $action = $this->request->str('action');
        if ($resource === 'vehicles' && in_array($action, ['upload_photo','delete_photo'], true)) {
            try {
                if ($action === 'upload_photo') { $this->uploadVehiclePhoto($role); Flash::success('Photo uploaded. Passengers can now see this vehicle.'); }
                else { $this->deleteVehiclePhoto($role); Flash::success('Photo removed.'); }
            } catch (\App\Core\AppException $e) { Flash::error($e->getMessage());
            } catch (\Throwable $e) { \App\Core\ErrorHandler::log('error','Vehicle photo failed: ' . $e->getMessage()); Flash::error('The photo could not be saved. Check the file and try again.'); }
            $this->redirect($this->request->path());
        }
        $db = Database::instance();
        try {
            $db->transaction(function () use ($db,$role,$resource): void {
                $action = $this->request->str('action');
                if ($resource === 'traffic') { $this->saveTraffic($role); return; }
                if ($action === 'create') { $this->create($role,$resource); return; }
                if ($resource === 'settings') { $this->settings($role); return; }
                if (!isset(self::RESOURCES[$resource])) throw new ValidationException('No update is available here.');
                [$table] = self::RESOURCES[$resource];
                [$where,$params] = $this->scope($role,$resource);
                $id = $this->request->int('id');
                $row = $db->first("SELECT * FROM `$table` WHERE id = ? AND ($where) FOR UPDATE", array_merge([$id],$params));
                if (!$row) throw new AuthorizationException('This record is outside your workspace.');
                $status = $this->request->str('status');
                $note = substr($this->request->str('note'),0,500);
                if ($resource === 'trips') {
                    (new TripModel())->updateStatus($id,$status,(int) Auth::id(),$note);
                } elseif ($resource === 'bookings') {
                    if ($action === 'collect_cash') {
                        \App\Services\CashPaymentService::collect($id);
                    } elseif ($action === 'refund_cash') {
                        \App\Services\CashPaymentService::refund($id);
                    } elseif ($action === 'board' && in_array($role, ['driver', 'operator'], true)) {
                        $trip = $db->first('SELECT status FROM trips WHERE id = ?', [(int) $row['trip_id']]);
                        if ($row['status'] !== 'confirmed' || !in_array($trip['status'],['boarding','in_transit'],true)) throw new ValidationException('Only confirmed bookings on boarding or running trips can board.');
                        $db->update('bookings',['boarded_at' => date('Y-m-d H:i:s')],'id = ?',[$id]);
                    } else {
                        if (!in_array($role,['admin','operator'],true)) throw new AuthorizationException('You cannot cancel this booking.');
                        (new BookingModel())->cancel($id,(int) Auth::id(),$note,$role === 'admin');
                    }
                } elseif ($resource === 'deliveries') {
                    if ($action === 'assign' && $role === 'admin') {
                        $vehicle = $db->first('SELECT * FROM vehicles WHERE id = ?',[$this->request->int('vehicle_id')]);
                        if (!$vehicle) throw new ValidationException('Choose a vehicle.');
                        (new DeliveryModel())->assign($id,(int) $vehicle['id'],null,$vehicle['operator_id'] ? (int) $vehicle['operator_id'] : null,(int) Auth::id());
                    } else (new DeliveryModel())->updateStatus($id,$status,['description'=>$note],(int) Auth::id());
                } elseif ($resource === 'complaints' && in_array($role,['admin','authority'],true)) {
                    (new ComplaintModel())->resolve($id,$status,$note,(int) Auth::id());
                } elseif ($resource === 'emergencies' && in_array($role,['admin','authority'],true)) {
                    (new EmergencyModel())->updateStatus($id,$status,$note,(int) Auth::id());
                } elseif ($resource === 'users' && $role === 'admin') {
                    if ($id === (int) Auth::id()) throw new ValidationException('Change your own profile from Profile.');
                    $this->enum($status,['active','inactive','suspended']);
                    (new UserModel())->setStatus($id,$status,(int) Auth::id());
                } elseif ($resource === 'operators' && $role === 'admin') {
                    throw new AuthorizationException('Operator licensing is handled by the transport authority. Admins provision the account; the authority approves, rejects or suspends it.');
                } elseif ($resource === 'operators' && $role === 'authority') {
                    $this->enum($status,['pending','approved','rejected','suspended']);
                    $db->update('operators',['approval_status'=>$status,'approved_by'=>Auth::id(),'approved_at'=>$status === 'approved' ? date('Y-m-d H:i:s') : null],'id = ?',[$id]);
                } elseif ($resource === 'vehicles' && in_array($role,['admin','operator'],true)) {
                    $this->enum($status,['active','inactive','maintenance','suspended']);
                    if ($row['status'] === 'on_trip') throw new ValidationException('Finish the vehicle trip before changing fleet status.');
                    $db->update('vehicles',['status'=>$status],'id = ?',[$id]);
                } elseif ($resource === 'drivers' && in_array($role,['admin','operator'],true)) {
                    $this->enum($status,['available','off_duty','suspended']);
                    if ($row['status'] === 'on_trip') throw new ValidationException('Finish the driver trip first.');
                    $db->update('drivers',['status'=>$status],'id = ?',[$id]);
                } elseif ($resource === 'routes' && in_array($role,['admin','operator'],true)) {
                    $this->enum($status,['active','inactive','suspended']);
                    $db->update('routes',['status'=>$status],'id = ?',[$id]);
                } elseif ($resource === 'ratings' && $role === 'admin') {
                    $this->enum($status, ['published', 'hidden']);
                    (new \App\Models\RatingModel())->setPublished($id, $status === 'published');
                } else throw new AuthorizationException('This action is not permitted.');
                ActivityLog::record('staff.updated',$id,$resource,'Updated ' . $resource . ' status to ' . $status);
            });
            Flash::success('Changes saved.');
        } catch (\App\Core\AppException $e) { Flash::error($e->getMessage());
        } catch (\Throwable $e) { \App\Core\ErrorHandler::log('error','Staff update failed: ' . $e->getMessage()); Flash::error('The change could not be saved. Check the values and try again.'); }
        $this->redirect($this->request->path());
    }

    private function ownedVehicle(int $id, string $role): void
    {
        [$where,$params] = $this->scope($role,'vehicles');
        if (!Database::instance()->exists("SELECT 1 FROM vehicles WHERE id = ? AND ($where)", array_merge([$id],$params))) {
            throw new AuthorizationException('This vehicle is outside your workspace.');
        }
    }

    private function uploadVehiclePhoto(string $role): void
    {
        $vehicleId = $this->request->int('id');
        if ($vehicleId < 1) throw new ValidationException('Choose a vehicle.');
        $this->ownedVehicle($vehicleId, $role);

        $file = $this->request->file('photo');
        if (!$file) throw new ValidationException('Choose a photo to upload.');
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new ValidationException('The upload did not complete. Check the file size and try again.');
        $maxBytes = (int) \App\Core\Config::get('uploads.max_bytes', 2 * 1024 * 1024);
        if ((int) $file['size'] > $maxBytes) throw new ValidationException('Photos must be at most ' . (int) round($maxBytes / 1048576) . ' MB.');
        $allowed = (array) \App\Core\Config::get('uploads.mime_types', ['image/jpeg','image/png','image/webp']);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? '';
        if ($ext === '' || !in_array($mime, $allowed, true)) throw new ValidationException('Upload a JPG, PNG or WebP image.');

        $model = new VehicleModel();
        if ($model->photoCount($vehicleId) >= VehicleModel::MAX_PHOTOS) {
            throw new ValidationException('This vehicle already has ' . VehicleModel::MAX_PHOTOS . ' photos. Remove one before adding another.');
        }
        $rel = (string) \App\Core\Config::get('uploads.vehicles', 'uploads/vehicles');
        $dir = PUBLIC_PATH . '/' . $rel . '/' . $vehicleId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new ValidationException('The upload folder is not writable on this server.');
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) throw new ValidationException('The photo could not be saved on the server.');
        @chmod($dest, 0644);
        try {
            $model->addImage($vehicleId, $rel . '/' . $vehicleId . '/' . $name);
        } catch (\Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        ActivityLog::record('staff.created', $vehicleId, 'vehicles', 'Uploaded a vehicle photo');
    }

    private function deleteVehiclePhoto(string $role): void
    {
        $imageId = $this->request->int('image_id');
        $vehicleId = $this->request->int('id');
        if ($imageId < 1 || $vehicleId < 1) throw new ValidationException('Photo not found.');
        $this->ownedVehicle($vehicleId, $role);
        $path = (new VehicleModel())->removeImage($imageId, $vehicleId);
        if ($path === null) throw new NotFoundException('Photo not found.');
        $public = realpath(PUBLIC_PATH);
        $file = realpath(PUBLIC_PATH . '/' . $path);
        if ($public && $file && str_starts_with($file, $public . DIRECTORY_SEPARATOR)) @unlink($file);
        ActivityLog::record('staff.updated', $vehicleId, 'vehicles', 'Removed a vehicle photo');
    }

    private function enum(string $value,array $values): void
    {
        if (!in_array($value,$values,true)) throw new ValidationException('Choose a valid status.');
    }

    private function required(string $name,int $max=120): string
    {
        $value = $this->request->str($name);
        if ($value === '' || mb_strlen($value) > $max) throw new ValidationException(ucwords(str_replace('_',' ',$name)) . ' is required and must be at most ' . $max . ' characters.');
        return $value;
    }

    private function create(string $role,string $resource): void
    {
        if (!in_array($role,['admin','operator'],true)) throw new AuthorizationException('You cannot create records here.');
        $db = Database::instance();
        $operator = $role === 'operator' ? (int) Auth::operatorId() : ($this->request->int('operator_id') ?: null);
        if ($role === 'operator' && !$operator) throw new AuthorizationException('An operator profile is required.');
        if ($resource === 'vehicles') {
            $type = $this->request->str('vehicle_type'); $this->enum($type,VehicleModel::TYPES);
            $capacity = $this->request->int('capacity');
            if ($capacity < 1 || $capacity > 200) throw new ValidationException('Capacity must be between 1 and 200.');
            $id = $db->insert('vehicles',['registration_number'=>strtoupper($this->required('registration_number',20)),'vehicle_type'=>$type,'capacity'=>$capacity,'operator_id'=>$operator,'status'=>'active']);
            for ($i=1;$i<=$capacity;$i++) $db->insert('seats',['vehicle_id'=>$id,'seat_number'=>(string)$i,'row_number'=>(int)ceil($i/4),'is_active'=>1]);
        } elseif ($resource === 'routes') {
            $fare=$this->request->float('base_fare'); $minutes=$this->request->int('duration_minutes');
            if ($fare <= 0 || $minutes < 1 || $minutes > 65535) throw new ValidationException('Enter a positive fare and duration.');
            foreach (['origin_latitude','destination_latitude'] as $key) if (!\App\Core\Validator::isLatitude($this->request->str($key))) throw new ValidationException('Enter valid route latitudes.');
            foreach (['origin_longitude','destination_longitude'] as $key) if (!\App\Core\Validator::isLongitude($this->request->str($key))) throw new ValidationException('Enter valid route longitudes.');
            $db->insert('routes',['origin_latitude'=>$this->request->float('origin_latitude'),'origin_longitude'=>$this->request->float('origin_longitude'),'destination_latitude'=>$this->request->float('destination_latitude'),'destination_longitude'=>$this->request->float('destination_longitude'),'route_code'=>$this->required('route_code',20),'name'=>$this->required('name'),'origin_name'=>$this->required('origin_name'),'destination_name'=>$this->required('destination_name'),'base_fare'=>$fare,'duration_minutes'=>$minutes,'operator_id'=>$operator,'status'=>'active']);
        } elseif ($resource === 'trips') {
            $vehicle=$db->first('SELECT * FROM vehicles WHERE id = ? FOR UPDATE',[$this->request->int('vehicle_id')]);
            $route=$db->first('SELECT * FROM routes WHERE id = ?',[$this->request->int('route_id')]);
            if (!$vehicle || !$route || $vehicle['status'] !== 'active' || $route['status'] !== 'active') throw new ValidationException('Choose an active vehicle and route.');
            if ($role === 'operator' && ((int)$vehicle['operator_id'] !== $operator || (int)$route['operator_id'] !== $operator)) throw new AuthorizationException('Choose your own vehicle and route.');
            $driver=$this->request->int('driver_id');
            if ($driver && !$db->exists("SELECT 1 FROM drivers WHERE id = ? AND status = 'available' AND (operator_id <=> ?)",[$driver,$vehicle['operator_id']])) throw new ValidationException('Choose an available driver from this operator.');
            $departure=strtotime($this->required('departure_time',30)); $arrival=strtotime($this->required('arrival_time',30));
            if (!$departure || !$arrival || $departure <= time() || $arrival <= $departure) throw new ValidationException('Departure must be in the future and arrival after departure.');
            $fare=$this->request->float('fare'); if ($fare <= 0) throw new ValidationException('Enter a positive fare.');
            (new TripModel())->createTrip(['vehicle_id'=>(int)$vehicle['id'],'route_id'=>(int)$route['id'],'driver_id'=>$driver ?: null,'operator_id'=>$vehicle['operator_id'],'departure_time'=>date('Y-m-d H:i:s',$departure),'arrival_time'=>date('Y-m-d H:i:s',$arrival),'fare'=>$fare],(int)Auth::id());
        } elseif (in_array($resource,['users','drivers','operators'],true)) {
            if ($resource === 'users' && $role !== 'admin') throw new AuthorizationException('Only admins create users.');
            $email=$this->required('email',150); $password=$this->required('password',100);
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new ValidationException('Enter a valid email.');
            if (!\App\Core\Validator::isStrongPassword($password)) throw new ValidationException(\App\Core\Validator::passwordHint());
            $accountRole=$resource === 'users' ? $this->request->str('role','passenger') : ($resource === 'drivers' ? 'driver' : 'operator');
            $this->enum($accountRole,['passenger','driver','operator','authority','admin']);
            if ($resource === 'users' && !in_array($accountRole,['passenger','authority','admin'],true)) throw new ValidationException('Create driver and operator accounts in their respective workspaces.');
            $profileTable=$accountRole === 'driver' ? 'drivers' : ($accountRole === 'operator' ? 'operators' : ($accountRole === 'passenger' ? 'passengers' : ''));
            $profile=$accountRole === 'driver' ? ['license_number'=>$this->required('license_number',60),'operator_id'=>$operator,'status'=>'available'] : ($accountRole === 'operator' ? ['company_name'=>$this->required('company_name',150),'license_number'=>$this->required('license_number',60)] : []);
            $userId=(new UserModel())->createUser(['email'=>$email,'password_hash'=>Auth::hashPassword($password),'first_name'=>$this->required('first_name',60),'last_name'=>$this->required('last_name',60),'phone'=>$this->required('phone',25)],[$accountRole],$profile,$profileTable);
            if ($accountRole === 'passenger') $db->insert('passengers',['user_id'=>$userId]);
        } else throw new ValidationException('No creation form is available here.');
        ActivityLog::record('staff.created',null,$resource,'Created ' . $resource);
    }

    private function settings(string $role): void
    {
        if ($role === 'admin') {
            $key=$this->required('setting_key',80);
            $allowed=['support_phone','support_email','emergency_hotline','cancellation_window_minutes'];
            if (!in_array($key,$allowed,true)) throw new ValidationException('This setting is not editable here.');
            $value=$this->required('setting_value',200);
            if ($key === 'support_email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new ValidationException('Enter a valid support email.');
            if ($key === 'cancellation_window_minutes' && (!ctype_digit($value) || (int)$value > 1440)) throw new ValidationException('Enter a cancellation window from 0 to 1440 minutes.');
            SettingsService::set($key,$value);
        } elseif ($role === 'operator') {
            Database::instance()->update('operators',['company_name'=>$this->required('company_name',150),'contact_email'=>$this->required('contact_email',150),'contact_phone'=>$this->required('contact_phone',25),'address'=>$this->request->str('address')],'id = ?',[(int)Auth::operatorId()]);
        } else throw new AuthorizationException('You cannot edit these settings.');
    }
}
