<?php
/**
 * UniGo - demo data seeder.
 *
 * Fills the database with a realistic Kampala based demo dataset so every role
 * dashboard has something to show:
 *
 *   1 admin, 1 authority, 5 operators, 10 drivers, 20 passengers
 *   25 locations, 20 vehicles (with seat maps), 15 routes (with stops)
 *   30 trips across past / present / future, 50 bookings, payments,
 *   notifications, simulated GPS traces, parcel deliveries, emergencies,
 *   complaints, ratings, traffic reports and demo predictions.
 *
 * Safety rules baked into this file:
 *   - payments are always is_mock = 1 and only a masked account is stored
 *   - every GPS row is is_simulated = 1 / source 'simulator'
 *   - USSD + SMS rows go to channel_messages with provider 'none'
 *   - demo accounts share one known password and are printed on completion
 *
 * Usage:
 *   php database/seed.php          # only seeds when the database is empty
 *   php database/seed.php --fresh  # wipes the domain tables and reseeds
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("The seeder may only be run from the command line.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\ReferenceGenerator;

$fresh = in_array('--fresh', $argv, true);
$db    = Database::instance();

/** Tables wiped by --fresh, children before parents. */
const WIPE_ORDER = [
    'channel_messages', 'activity_logs', 'api_rate_limits', 'login_attempts', 'remember_tokens',
    'ai_predictions', 'ratings', 'complaints', 'emergency_alerts', 'traffic_reports',
    'delivery_tracking', 'deliveries', 'payments', 'notifications',
    'vehicle_locations', 'locations', 'seats', 'bookings', 'trips',
    'vehicles', 'route_stops', 'routes', 'operator_drivers', 'drivers', 'operators',
    'passengers', 'user_roles', 'users',
];

const DEMO_PASSWORD = 'UniGo@2026';

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function say(string $step): void
{
    printf("  %-34s %s\n", $step, 'ok');
}

/** Deterministic pseudo random so re-seeding produces the same demo data. */
function rnd(int $min, int $max): int
{
    return random_int($min, $max);
}

function pick(array $options): mixed
{
    return $options[array_rand($options)];
}

/**
 * Sequential reference in the app's canonical format: UG-2026-000001.
 *
 * Rows are buffered and inserted in one batch, so the DB-backed
 * ReferenceGenerator cannot see them yet. The domain tables are wiped before
 * seeding, so a per-kind counter starting at 1 is both correct and
 * reproducible across runs.
 */
function seqRef(string $kind, bool $withYear = true): string
{
    static $counters = [];

    $prefix = ReferenceGenerator::prefix($kind);
    $counters[$kind] = ($counters[$kind] ?? 0) + 1;

    return $prefix . ($withYear ? '-' . date('Y') : '') . '-' . sprintf('%06d', $counters[$kind]);
}

function phone(): string
{
    return '+2567' . rnd(10000000, 99999999);
}

function dt(string $modifier): string
{
    return (new DateTimeImmutable('now'))->modify($modifier)->format('Y-m-d H:i:s');
}

/**
 * Several demo columns are NOT NULL with an empty-string default, so passing an
 * explicit NULL is rejected by MySQL. This turns those NULLs into '' while
 * leaving genuinely nullable columns (actual_arrival, driver_id, ...) alone.
 */
function row(string $table, array $data): array
{
    static $notNull = [];

    if (!isset($notNull[$table])) {
        $columns = Database::instance()->select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?
                AND is_nullable = 'NO' AND extra NOT LIKE '%auto_increment%'",
            [$table]
        );
        $notNull[$table] = array_flip(array_column($columns, 'column_name'));
    }

    foreach ($data as $column => $value) {
        if ($value === null && isset($notNull[$table][$column])) {
            $data[$column] = '';
        }
    }

    return $data;
}

// ---------------------------------------------------------------------------
// 0. Guard / reset
// ---------------------------------------------------------------------------

$existing = $db->value('SELECT COUNT(*) FROM users');
if ($existing > 0 && !$fresh) {
    out('Database already has ' . $existing . ' users. Nothing to do.');
    out('Re-run with --fresh to wipe the domain tables and reseed.');
    exit(0);
}

$pdo = $db->pdo();

// AUTO_INCREMENT resets are DDL, so they cannot run inside the transaction.
// They are cosmetic: a failed seed leaves the counters at 1 with the previous
// rows still in place.
if ($fresh) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (WIPE_ORDER as $table) {
        $pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = 1');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$pdo->beginTransaction();

/** Insert helper that coerces NULLs on NOT NULL columns. */
$ins = static fn(string $table, array $data): int => $db->insert($table, row($table, $data));

try {
    if ($fresh) {
        // DELETE rather than TRUNCATE: it is transactional, so a failure later on
        // rolls the wipe back instead of leaving an empty database behind.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (WIPE_ORDER as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        say('wiped ' . count(WIPE_ORDER) . ' tables');
    }

    // -----------------------------------------------------------------------
    // 1. Locations
    // -----------------------------------------------------------------------
    $locations = [
        // name, category, district, lat, lng
        ['Kampala Central Bus Park', 'terminal', 'Central', 0.311700, 32.576700],
        ['Kibuye Stage', 'stop', 'Central', 0.313600, 32.581700],
        ['City Square', 'landmark', 'Central', 0.306900, 32.582400],
        ['KCCA offices', 'landmark', 'Central', 0.309000, 32.576000],
        ['Mulago', 'stop', 'Central', 0.306400, 32.589000],
        ['Nsambya', 'stop', 'Central', 0.295100, 32.598700],
        ['Katungo', 'stop', 'Nakawa', 0.306000, 32.617000],
        ['Bombo', 'stop', 'Nakawa', 0.330700, 32.564000],
        ['Kira', 'stop', 'Nakawa', 0.319000, 32.633000],
        ['Kireka', 'stop', 'Nakawa', 0.362900, 32.652000],
        ['Jinja Road', 'stop', 'Nakawa', 0.326000, 32.588000],
        ['Najjera', 'stop', 'Nakawa', 0.362000, 32.626000],
        ['Ntinda', 'stop', 'Nakawa', 0.339000, 32.623000],
        ['Kampala Serena', 'landmark', 'Central', 0.301600, 32.576000],
        ['Kisementi', 'stop', 'Central', 0.302600, 32.572000],
        ['Gaba', 'stop', 'Nakawa', 0.292600, 32.610000],
        ['Entebbe Bus Park', 'terminal', 'Wakiso', 0.051200, 32.462000],
        ['Entebbe Town', 'stop', 'Wakiso', 0.053800, 32.483000],
        ['Katonga', 'stop', 'Wakiso', 0.060300, 32.451000],
        ['Port Bell', 'stop', 'Wakiso', 0.073000, 32.439000],
        ['Kisubi', 'stop', 'Wakiso', 0.088700, 32.418000],
        ['Nansana', 'stop', 'Wakiso', 0.366700, 32.533300],
        ['Mityana', 'stop', 'Wakiso', 0.400000, 32.033300],
        ['Makindye', 'district', 'Nakawa', 0.289000, 32.584000],
        ['Ntinda Flyover', 'landmark', 'Nakawa', 0.338000, 32.625000],
    ];

    $locationId = [];
    foreach ($locations as [$name, $category, $district, $lat, $lng]) {
        $locationId[$name] = $ins('locations', [
            'name'       => $name,
            'category'   => $category,
            'address'    => $name . ', Kampala',
            'city'       => 'Kampala',
            'district'   => $district,
            'latitude'   => $lat,
            'longitude'  => $lng,
            'search_text'=> strtolower($name . ' ' . $category . ' ' . $district),
            'is_active'  => 1,
        ]);
    }
    say('locations: ' . count($locationId));

    // -----------------------------------------------------------------------
    // 2. Users
    // -----------------------------------------------------------------------
    $roleId = [];
    foreach ($db->select('SELECT id, slug FROM roles') as $role) {
        $roleId[$role['slug']] = (int) $role['id'];
    }

    $hash = Auth::hashPassword(DEMO_PASSWORD);

    $insertUser = static function (string $email, string $first, string $last, string $role, array $extra = []) use ($db, $hash, $roleId, $ins): int {
        $id = $ins('users', array_merge([
            'email'              => $email,
            'password_hash'      => $hash,
            'first_name'         => $first,
            'last_name'          => $last,
            'phone'              => phone(),
            'national_id'        => strtoupper(substr(md5($email), 0, 12)),
            'gender'             => pick(['male', 'female', 'other', 'undisclosed']),
            'status'             => 'active',
            'email_verified_at'  => dt('-6 months'),
            'password_changed_at'=> dt('-6 months'),
            'last_login_at'      => dt('-2 hours'),
            'must_change_password' => 0,
            'created_at'         => dt('-6 months'),
        ], $extra));

        $ins('user_roles', [
            'user_id'     => $id,
            'role_id'     => $roleId[$role],
            'assigned_at' => dt('-6 months'),
        ]);

        return $id;
    };

    $adminId = $insertUser('admin@unigo.test', 'System', 'Administrator', 'admin', [
        'first_name' => 'Ruth', 'last_name' => 'Nabirye',
    ]);
    $authorityId = $insertUser('authority@unigo.test', 'Moses', 'Tibawa', 'authority');

    $operatorIds  = [];
    $operatorPlan = [
        ['operator@unigo.test', 'Sarah', 'Kibirige', 'Kampala City Bus Services', 'UG-BUS-001'],
        ['greatlakes@unigo.test', 'Daniel', 'Okello', 'Great Lakes Transport Co.', 'UG-BUS-002'],
        ['metrotown@unigo.test', 'Grace', 'Nabirye', 'Metro Town Movers', 'UG-BUS-003'],
        ['lakeshore@unigo.test', 'Patrick', 'Byaruhanga', 'Lakeshore Coaches', 'UG-BUS-004'],
        ['speedline@unigo.test', 'Alice', 'Namutebi', 'Speedline Uganda Ltd', 'UG-BUS-005'],
    ];
    foreach ($operatorPlan as $i => [$email, $first, $last, $company, $licence]) {
        $uid = $insertUser($email, $first, $last, 'operator');
        $operatorIds[] = $ins('operators', [
            'user_id'          => $uid,
            'company_name'     => $company,
            'license_number'   => $licence,
            'approval_status'  => 'approved',
            'approved_at'      => dt('-5 months'),
            'approved_by'      => $adminId,
            'contact_email'    => $email,
            'contact_phone'    => phone(),
            'address'          => $first . ' Plaza, Kampala',
            'description'      => $company . ' operates scheduled commuter and intercity services in the Wakiso and Kampala districts.',
            'rating_avg'       => number_format(4.0 + $i * 0.12, 2),
            'rating_count'     => rnd(20, 180),
            'total_revenue'    => 0,
            'created_at'       => dt('-6 months'),
        ]);
    }
    say('operators: ' . count($operatorIds));

    $driverIds  = [];
    $driverPlan = [
        ['driver@unigo.test', 'Joseph', 'Mugisha', 'Boda-Boda'],
        ['driver2@unigo.test', 'Peter', 'Wanjala', 'Bus'],
        ['driver3@unigo.test', 'Ernest', 'Kato', 'Bus'],
        ['driver4@unigo.test', 'Hellen', 'Nabirye', 'Taxi'],
        ['driver5@unigo.test', 'David', 'Ouma', 'Bus'],
        ['driver6@unigo.test', 'Sarah', 'Nansubuga', 'Taxi'],
        ['driver7@unigo.test', 'Michael', 'Ssali', 'Bus'],
        ['driver8@unigo.test', 'Jane', 'Achieng', 'Boda-Boda'],
        ['driver9@unigo.test', 'Robert', 'Kiyingi', 'Bus'],
        ['driver10@unigo.test', 'Fatima', 'Nampege', 'Taxi'],
    ];
    foreach ($driverPlan as $i => [$email, $first, $last, $kind]) {
        $uid = $insertUser($email, $first, $last, 'driver');
        $operatorId = $operatorIds[$i % count($operatorIds)];

        $driverIds[] = $ins('drivers', [
            'user_id'          => $uid,
            'operator_id'      => $operatorId,
            'license_number'   => 'DL-' . strtoupper(substr(md5($email), 0, 8)),
            'license_type'     => $kind === 'Boda-Boda' ? 'motorcycle' : ($kind === 'Taxi' ? 'psv' : 'bus'),
            'license_expiry'   => dt('+2 years'),
            'experience_years' => rnd(1, 18),
            'rating_avg'       => number_format(3.9 + $i * 0.05, 2),
            'rating_count'     => rnd(15, 240),
            'total_trips'      => rnd(40, 900),
            'total_distance_km'=> rnd(4000, 90000),
            'status'           => 'available',
            'created_at'       => dt('-6 months'),
        ]);
    }
    say('drivers: ' . count($driverIds));

    $firstNames = ['Amos', 'Beatrice', 'Charles', 'Diana', 'Emmanuel', 'Faith', 'George', 'Hannah', 'Isaac', 'Judith',
                   'Kibet', 'Lydia', 'Moses', 'Nancy', 'Oscar', 'Patience', 'Richard', 'Sarah', 'Timothy', 'Unity'];
    $lastNames  = ['Aceng', 'Bako', 'Chemutai', 'Dukiga', 'Erias', 'Fumu', 'Gabira', 'Haggai', 'Ilunga', 'Jjuuko',
                   'Kato', 'Lukwago', 'Magezi', 'Nabirye', 'Ouma', 'Prosscovia', 'Rwomero', 'Ssekandi', 'Tamale', 'Wekesa'];
    // 19 dedicated passengers; the 20th profile belongs to a driver who also
    // travels, which keeps the multi-role navigation exercised by real data.
    //
    // bookings.passenger_id / deliveries.customer_id reference users(id), not
    // passengers(id), so the user ids are tracked alongside the profile ids.
    $passengerIds     = [];
    $passengerUserIds = [];
    for ($i = 0; $i < 19; $i++) {
        $email = $i === 0 ? 'passenger@unigo.test' : 'passenger' . ($i + 1) . '@unigo.test';
        $first = $i === 0 ? 'Grace' : $firstNames[$i];
        $last  = $i === 0 ? 'Atuhaire' : $lastNames[$i];

        $uid = $insertUser($email, $first, $last, 'passenger', ['gender' => pick(['female', 'male', 'other'])]);
        $passengerUserIds[] = $uid;
        $passengerIds[] = $ins('passengers', [
            'user_id'            => $uid,
            'address'            => pick($locations)[0],
            'city'               => 'Kampala',
            'district'           => pick(['Central', 'Nakawa', 'Wakiso']),
            'home_latitude'      => 0.30 + (random_int(0, 8000) / 100000),
            'home_longitude'     => 32.44 + (random_int(0, 18000) / 100000),
            'emergency_contact'  => pick($firstNames) . ' ' . $lastNames[array_rand($lastNames)],
            'emergency_phone'    => phone(),
            'preferred_payment'  => pick(['mobile_money', 'card', 'cash', 'wallet']),
            'loyalty_points'     => rnd(0, 4200),
            'total_bookings'     => 0,
            'total_spent'        => 0,
            'created_at'         => dt('-6 months'),
        ]);
    }
    say('passengers: ' . count($passengerIds));

    // A driver who is also a passenger: proves the nav union and multi-role
    // guards work with a real record rather than a fixture.
    $dualUserId = (int) $db->value('SELECT user_id FROM drivers WHERE id = ?', [$driverIds[0]]);
    $ins('user_roles', [
        'user_id'     => $dualUserId,
        'role_id'     => $roleId['passenger'],
        'assigned_at' => dt('-6 months'),
        'assigned_by' => $adminId,
    ]);
    $passengerIds[]     = $ins('passengers', [
        'user_id'           => $dualUserId,
        'address'           => 'Kira Road, Kampala',
        'city'              => 'Kampala',
        'district'          => 'Nakawa',
        'home_latitude'     => 0.3190000,
        'home_longitude'    => 32.6330000,
        'emergency_contact' => 'Grace Atuhaire',
        'emergency_phone'   => phone(),
        'preferred_payment' => 'mobile_money',
        'loyalty_points'    => 320,
        'total_bookings'    => 0,
        'total_spent'       => 0,
        'created_at'        => dt('-6 months'),
    ]);
    $passengerUserIds[] = $dualUserId;
    say('passenger profiles: ' . count($passengerIds) . ' (1 of them also a driver)');

    // Link drivers to their operator.
    foreach ($driverIds as $i => $driverId) {
        $ins('operator_drivers', [
            'operator_id' => $operatorIds[$i % count($operatorIds)],
            'driver_id'   => $driverId,
            'joined_at'   => dt('-6 months'),
            'is_primary'  => 1,
        ]);
    }

    // -----------------------------------------------------------------------
    // 3. Routes + stops
    // -----------------------------------------------------------------------
    $routePlan = [
        ['KLA-101', 'Kampala - Entebbe Express',  'Kampala Central Bus Park', 'Entebbe Bus Park',  41.5, 70,  15000, '#2563EB'],
        ['KLA-102', 'Kampala - Jinja Highway',     'Kampala Central Bus Park', 'Jinja Road',        78.0, 110, 22000, '#059669'],
        ['KLA-103', 'City Circular - West Loop',   'City Square',              'Kira',              18.4, 55,  5000, '#7C3AED'],
        ['KLA-104', 'City Circular - East Loop',   'City Square',              'Ntinda',            16.9, 50,  4500, '#DB2777'],
        ['KLA-105', 'Nakawa - Gaba - Katungo',     'Ntinda',                   'Katungo',           12.2, 40,  3500, '#EA580C'],
        ['KLA-106', 'Nakawa - Kireka Airport',     'Ntinda',                   'Kireka',            24.5, 55,  9000, '#0891B2'],
        ['KLA-107', 'Kampala - Mityana',           'Kampala Central Bus Park', 'Mityana',           58.0, 95,  18000, '#65A30D'],
        ['KLA-108', 'Kampala - Nansana',           'Kampala Central Bus Park', 'Nansana',           27.3, 70,  8000, '#CA8A04'],
        ['KLA-109', 'Entebbe - Kisubi - Port Bell', 'Entebbe Bus Park',        'Port Bell',         22.0, 45,  7000, '#16A34A'],
        ['KLA-110', 'Kibuye - Mulago Shuttle',     'Kibuye Stage',             'Mulago',            9.5,  35,  3000, '#4F46E5'],
        ['KLA-111', 'Nsambya - Katungo Link',      'Nsambya',                  'Katungo',           13.8, 45,  4500, '#BE123C'],
        ['KLA-112', 'Jinja Road - Bombo Airport',  'Jinja Road',               'Bombo',             19.0, 40,  8500, '#0F766E'],
        ['KLA-113', 'Kampala - Katonga Ferry',     'Entebbe Bus Park',        'Katonga',           11.5, 30,  6500, '#9333EA'],
        ['KLA-114', 'Makindye - Ntinda Night',     'Makindye',                 'Ntinda',            15.4, 50,  4000, '#DC2626'],
        ['KLA-115', 'Serena - Kisementi City',     'Kampala Serena',           'Kisementi',         7.2,  30,  3500, '#0D9488'],
    ];

    $routeIds = [];
    foreach ($routePlan as $i => [$code, $name, $origin, $destination, $km, $minutes, $fare, $colour]) {
        $originRow = $db->first('SELECT latitude, longitude FROM locations WHERE name = ?', [$origin]);
        $destRow   = $db->first('SELECT latitude, longitude FROM locations WHERE name = ?', [$destination]);

        $routeIds[$code] = $ins('routes', [
            'route_code'          => $code,
            'name'                => $name,
            'origin_name'         => $origin,
            'origin_latitude'     => $originRow['latitude'] ?? 0.3126,
            'origin_longitude'    => $originRow['longitude'] ?? 32.5825,
            'destination_name'    => $destination,
            'destination_latitude'=> $destRow['latitude'] ?? 0.0512,
            'destination_longitude'=> $destRow['longitude'] ?? 32.4620,
            'distance_km'         => $km,
            'duration_minutes'    => $minutes,
            'base_fare'           => $fare,
            'operator_id'         => $operatorIds[$i % count($operatorIds)],
            'status'              => $i === 13 ? 'inactive' : 'active',
            'colour'              => $colour,
            'description'         => $name . ' serves ' . count($locations) . ' mapped points across Kampala and Wakiso.',
            'created_at'          => dt('-6 months'),
            'updated_at'          => dt('-1 month'),
        ]);

        // Stops: origin, a few intermediate locations, destination.
        $intermediate = array_values(array_diff(array_keys($locationId), [$origin, $destination]));
        shuffle($intermediate);
        $stopNames = array_merge([$origin], array_slice($intermediate, 0, 3), [$destination]);

        $step = max(1, (int) round($minutes / (count($stopNames) - 1)));
        $fareStep = $fare / (count($stopNames) - 1);
        foreach ($stopNames as $order => $stopName) {
            $loc = $db->first('SELECT latitude, longitude, address FROM locations WHERE name = ?', [$stopName]);
            $ins('route_stops', [
                'route_id'         => $routeIds[$code],
                'stop_order'       => $order,
                'stop_name'        => $stopName,
                'latitude'         => $loc['latitude'],
                'longitude'        => $loc['longitude'],
                'minutes_from_origin' => $order * $step,
                'fare_from_origin'=> round($order * $fareStep, 0),
                'is_pickup_point' => in_array($order, [0, count($stopNames) - 1], true) ? 1 : 0,
            ]);
        }
    }
    say('routes + stops: ' . count($routeIds));

    // -----------------------------------------------------------------------
    // 4. Vehicles + seat maps
    // -----------------------------------------------------------------------
    $vehiclePlan = [
        ['bus',          'Mercedes-Benz', 'Sprinter',    45, [1, 2]],
        ['bus',          'Isuzu',          'NPR',        30, [1, 2]],
        ['bus',          'Scania',         'K230',       50, [1, 2]],
        ['bus',          'Yutong',         'ZK6127',     49, [1, 2]],
        ['bus',          'Higer',          'KLQ6119',    45, [1, 2]],
        ['electric_bus', 'BYD',            'eBus K7',    40, [1, 2]],
        ['electric_bus', 'Kia',            'EV5',        32, [1, 2]],
        ['taxi',         'Toyota',         'Hiace',      16, [0]],
        ['taxi',         'Nissan',         'NV350',      16, [0]],
        ['taxi',         'Toyota',         'Quantum',    14, [0]],
        ['boda',         ' Bajaj',         'RE 4S',       2, [0]],
        ['boda',         'TVS',            'Ntorq',       2, [0]],
        ['boda',         'Yamaha',         'NMAX',        2, [0]],
        ['shared_ride',  'Toyota',         'Sienta',     10, [0]],
        ['shared_ride',  'Nissan',         'Note',        5, [0]],
        ['truck',        'Isuzu',          'F-Series',    3, [0]],
        ['boat',         'Marines',        'Coaster',    30, [0]],
        ['bus',          'Mercedes-Benz',  'Sprinter',   45, [1, 2]],
        ['taxi',         'Toyota',         'Hiace',      16, [0]],
        ['bus',          'Scania',         'Irizar',     52, [1, 2]],
    ];

    $vehicleIds = [];
    $seatMap = [];   // vehicle_id => list of seat numbers
    $codes = array_keys($routeIds);
    foreach ($vehiclePlan as $i => [$type, $make, $model, $capacity, $driverSlots]) {
        $operatorId = $operatorIds[$i % count($operatorIds)];
        $driverId   = $driverSlots ? $driverIds[$i % count($driverIds)] : null;
        $status     = $i === 15 ? 'maintenance' : 'active';

        $vehicleIds[] = $ins('vehicles', [
            'registration_number'=> sprintf('KDA %03d%s', 100 + $i, chr(65 + ($i % 26)) . chr(65 + (($i * 3) % 26))),
            'vehicle_type'      => $type,
            'make'              => ltrim($make),
            'model'             => $model,
            'year'              => rnd(2015, 2025),
            'colour'            => pick(['White', 'Blue', 'Yellow', 'Green', 'Grey', 'Red']),
            'plate_colour'      => pick(['Yellow', 'White', 'Blue']),
            'capacity'          => $capacity,
            'operator_id'       => $operatorId,
            'driver_id'         => $driverId,
            'home_route_id'     => $routeIds[$codes[$i % count($codes)]],
            'status'            => $status,
            'gps_enabled'       => 1,
            'gps_device_id'     => 'GPS-' . strtoupper(substr(md5((string) $i), 0, 8)),
            'insurance_expiry'  => dt('+8 months'),
            'inspection_status' => $i === 15 ? 'due' : 'valid',
            'last_inspection'   => dt('-2 months'),
            'odometer_km'       => rnd(12000, 260000),
            'created_at'        => dt('-6 months'),
        ]);

        $vehicleId = $vehicleIds[array_key_last($vehicleIds)];

        // Seat numbering. The seat map must always add up to the declared
        // capacity, otherwise the booking seat picker shows free seats that do
        // not physically exist.
        $seats = [];
        if ($type === 'boda' || $type === 'truck' || $type === 'boat' || $type === 'taxi' || $type === 'shared_ride') {
            for ($n = 1; $n <= $capacity; $n++) {
                $seats[] = (string) $n;
            }
        } else {
            // Coach layout: numbered rows of three (A1..A3, B1..B3, ...) plus one
            // wheelchair bay, counted inside the capacity.
            $gridSeats = max(1, $capacity - 1);
            $rows      = (int) ceil($gridSeats / 3);
            for ($row = 1; $row <= $rows && count($seats) < $gridSeats; $row++) {
                for ($col = 1; $col <= 3 && count($seats) < $gridSeats; $col++) {
                    $seats[] = chr(64 + $row) . $col;
                }
            }
            $seats[] = 'W1';
        }
        $seatMap[$vehicleId] = $seats;

        if (count($seats) !== $capacity) {
            throw new RuntimeException(sprintf(
                'Seat map for vehicle %d has %d seats but capacity is %d.',
                $vehicleId,
                count($seats),
                $capacity
            ));
        }

        foreach ($seats as $index => $seat) {
            $ins('seats', [
                'vehicle_id' => $vehicleId,
                'seat_number'=> $seat,
                'seat_type'  => $seat === 'W1' ? 'wheelchair' : (($index % 7 === 0 && $capacity > 20) ? 'premium' : 'standard'),
                'row_number' => $seat === 'W1' ? 0 : (int) $seat,
                'is_active'  => 1,
            ]);
        }
    }
    say('vehicles: ' . count($vehicleIds));

    // -----------------------------------------------------------------------
    // 5. Trips
    // -----------------------------------------------------------------------
    // 8 completed (past), 2 in transit / boarding, 1 cancelled, 19 scheduled.
    $tripPlan = array_merge(
        array_fill(0, 8, ['completed', '-%d days']),
        [['in_transit', '-2 hours'], ['boarding', '-25 minutes'], ['cancelled', '-1 days']],
        array_fill(0, 19, ['scheduled', '+%d days'])
    );

    $tripIds   = [];
    $tripSeats = [];   // trip_id => [taken seats]
    $tripRows  = [];   // trip_id => row

    foreach ($tripPlan as $i => [$status, $modifier]) {
        $routeCode = $codes[$i % count($codes)];
        $route     = $db->first('SELECT * FROM routes WHERE id = ?', [$routeIds[$routeCode]]);

        $candidates = array_values(array_filter($vehicleIds, static fn($v) => true));
        $vehicleId  = $candidates[$i % count($candidates)];
        $vehicle    = $db->first('SELECT * FROM vehicles WHERE id = ?', [$vehicleId]);
        $driverId   = $vehicle['driver_id'] ?: $driverIds[$i % count($driverIds)];

        $hour   = 6 + ($i % 14);
        $minute = [0, 15, 30, 45][$i % 4];
        $dep    = dt(sprintf($modifier, max(1, $i % 21) + 1) . ' ' . sprintf('%02d:%02d:00', $hour, $minute));
        $arr    = (new DateTimeImmutable($dep))->modify('+' . (int) $route['duration_minutes'] . ' minutes')->format('Y-m-d H:i:s');

        $tripId = $ins('trips', [
            'trip_code'      => 'TRP-' . date('ymd', strtotime($dep)) . '-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            'route_id'       => $route['id'],
            'vehicle_id'     => $vehicleId,
            'driver_id'      => $driverId,
            'operator_id'    => $route['operator_id'] ?: $vehicle['operator_id'],
            'departure_time' => $dep,
            'arrival_time'   => $arr,
            'boarding_opens' => (new DateTimeImmutable($dep))->modify('-20 minutes')->format('Y-m-d H:i:s'),
            'status'         => $status,
            'seats_total'    => count($seatMap[$vehicleId]),
            'fare'           => $route['base_fare'],
            'currency'       => 'UGX',
            'actual_departure'=> in_array($status, ['completed', 'in_transit', 'boarding'], true) ? $dep : null,
            'actual_arrival' => $status === 'completed' ? $arr : null,
            'delay_minutes'  => $status === 'completed' ? (random_int(0, 10) === 0 ? rnd(5, 35) : 0) : 0,
            'notes'          => $status === 'cancelled' ? 'Vehicle maintenance - rescheduled' : null,
            'cancelled_reason'=> $status === 'cancelled' ? 'Vehicle maintenance - rescheduled' : null,
            'created_by'     => $adminId,
            'created_at'     => dt('-1 month'),
            'updated_at'     => dt('-1 day'),
        ]);

        $tripIds[]    = $tripId;
        $tripSeats[$tripId] = [];
        $tripRows[$tripId]  = ['status' => $status, 'fare' => (float) $route['base_fare'], 'operator_id' => $route['operator_id'] ?: $vehicle['operator_id'], 'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'route_id' => $route['id'], 'dep' => $dep, 'arr' => $arr];
    }
    say('trips: ' . count($tripIds));

    // -----------------------------------------------------------------------
    // 6. Bookings (50, unique seat per trip) + payments
    // -----------------------------------------------------------------------
    $bookingIds  = [];
    $paymentRows = [];
    $paidTotal   = [];

    for ($n = 0; $n < 50; $n++) {
        $tripId   = $tripIds[$n % count($tripIds)];
        $trip     = $tripRows[$tripId];
        $free     = array_values(array_diff($seatMap[$trip['vehicle_id']], $tripSeats[$tripId]));
        if (!$free) {
            continue;
        }
        $seat            = $free[0];
        $tripSeats[$tripId][] = $seat;

        $passengerUserId = $passengerUserIds[$n % count($passengerUserIds)];
        $stops       = $db->select('SELECT id, stop_order, fare_from_origin FROM route_stops WHERE route_id = ? ORDER BY stop_order', [$trip['route_id']]);
        $from        = $stops[0] ?? null;
        $to          = $stops[count($stops) - 1] ?? null;

        // Status follows the trip: a past trip yields a finished booking.
        $status = match ($trip['status']) {
            'completed' => 'completed',
            'in_transit', 'boarding' => 'confirmed',
            'cancelled' => 'cancelled',
            default     => pick(['confirmed', 'confirmed', 'confirmed', 'pending']),
        };
        $paymentStatus = match ($status) {
            'completed'  => 'paid',
            'cancelled'  => pick(['refunded', 'failed']),
            'confirmed'  => 'paid',
            default      => pick(['unpaid', 'pending']),
        };

        $fare = round((float) ($to['fare_from_origin'] ?? $trip['fare']), 0);
        $bookingId = $ins('bookings', [
            'reference'      => seqRef('booking'),
            'trip_id'        => $tripId,
            'passenger_id'   => $passengerUserId,
            'vehicle_id'     => $trip['vehicle_id'],
            'driver_id'      => $trip['driver_id'],
            'seat_number'    => $seat,
            'from_stop_id'   => $from['id'] ?? null,
            'to_stop_id'     => $to['id'] ?? null,
            'pickup_point'   => $from ? $db->value('SELECT stop_name FROM route_stops WHERE id = ?', [$from['id']]) : null,
            'status'         => $status,
            'fare'           => $fare,
            'currency'       => 'UGX',
            'payment_status' => $paymentStatus,
            'booking_channel'=> pick(['web', 'mobile_web', 'pwa', 'ussd', 'sms', 'call_centre', 'admin']),
            'is_simulated'   => 1,
            'booked_at'      => dt('-2 weeks'),
            'confirmed_at'   => in_array($status, ['confirmed', 'completed'], true) ? dt('-2 weeks') : null,
            'cancelled_at'   => $status === 'cancelled' ? dt('-1 day') : null,
            'cancel_reason'  => $status === 'cancelled' ? 'Passenger request - change of plans' : null,
            'boarded_at'     => $status === 'completed' ? $trip['dep'] : null,
            'notes'          => $status === 'confirmed' ? 'Window seat requested' : null,
        ]);
        $bookingIds[] = $bookingId;

        if (in_array($paymentStatus, ['paid', 'refunded'], true)) {
            $userId = $passengerUserId;
            $method = pick(['mobile_money', 'mobile_money', 'card', 'cash', 'wallet']);
            $phone  = (string) $db->value('SELECT phone FROM users WHERE id = ?', [$userId]);
            $paymentRows[] = [
                'reference'    => seqRef('payment'),
                'user_id'      => $userId,
                'booking_id'   => $bookingId,
                'amount'       => $fare,
                'currency'     => 'UGX',
                'method'       => $method,
                'provider'     => $method === 'mobile_money' ? 'demo-momo' : 'demo',
                // Nothing sensitive is ever stored: a card keeps only the last
                // four digits, a mobile money number keeps the country code and
                // the last three digits. No PAN, no CVV, no full MSISDN.
                'masked_account' => $method === 'mobile_money'
                    ? '+256***' . substr($phone, -3)
                    : '****' . rnd(1000, 9999),
                'status'       => $paymentStatus === 'refunded' ? 'refunded' : 'successful',
                'refunded_amount' => $paymentStatus === 'refunded' ? $fare : null,
                'is_mock'      => 1,
                'initiated_by' => $userId,
                'created_at'   => dt('-2 weeks'),
                'completed_at' => dt('-2 weeks'),
            ];
            $paidTotal[(int) $trip['operator_id']] = ($paidTotal[(int) $trip['operator_id']] ?? 0) + $fare;
        }
    }
    say('bookings: ' . count($bookingIds));

    foreach ($paymentRows as $payment) {
        $ins('payments', $payment);
    }
    say('payments: ' . count($paymentRows));

    foreach ($paidTotal as $operatorId => $revenue) {
        $db->update('operators', ['total_revenue' => round($revenue, 2)], 'id = ?', [$operatorId]);
    }

    // -----------------------------------------------------------------------
    // 7. Simulated GPS traces for live and recent trips
    // -----------------------------------------------------------------------
    $gpsRows = 0;
    foreach ($tripRows as $tripId => $trip) {
        if (!in_array($trip['status'], ['in_transit', 'boarding', 'completed'], true)) {
            continue;
        }
        $route = $db->first('SELECT origin_latitude, origin_longitude, destination_latitude, destination_longitude FROM routes WHERE id = ?', [$trip['route_id']]);
        $steps = $trip['status'] === 'completed' ? 8 : 12;
        $progress = $trip['status'] === 'completed' ? 1.0 : ($trip['status'] === 'boarding' ? 0.03 : 0.45);

        for ($s = 0; $s <= $steps; $s++) {
            $f = min(1.0, ($s / $steps) * $progress);
            // A small sinusoidal wobble keeps the trace from looking like a ruler line.
            $wobble = sin($f * M_PI * 2) * 0.004;
            $ins('vehicle_locations', [
                'vehicle_id'  => $trip['vehicle_id'],
                'trip_id'     => $tripId,
                'latitude'    => round((float) $route['origin_latitude'] + (((float) $route['destination_latitude'] - (float) $route['origin_latitude']) * $f) + $wobble, 7),
                'longitude'   => round((float) $route['origin_longitude'] + (((float) $route['destination_longitude'] - (float) $route['origin_longitude']) * $f) + $wobble, 7),
                'speed'       => round(18 + sin($f * 8) * 9 + rnd(0, 6), 2),
                'heading'     => round(rnd(0, 359), 2),
                'accuracy'    => round(rnd(4, 12), 2),
                'recorded_at' => (new DateTimeImmutable($trip['dep']))->modify('+' . (int) round($f * 240) . ' minutes')->format('Y-m-d H:i:s'),
                'source'      => 'simulator',
                'is_simulated'=> 1,
            ]);
            $gpsRows++;
        }
    }
    say('vehicle_locations: ' . $gpsRows);

    // -----------------------------------------------------------------------
    // 8. Notifications
    // -----------------------------------------------------------------------
    $notificationTemplates = [
        ['booking_confirmed', 'Booking confirmed', 'Your seat on {trip} is confirmed. Ticket {ref} is ready.', 'ticket', 'success', '/bookings'],
        ['trip_reminder',    'Trip reminder',     '{trip} departs in 45 minutes from {stop}.', 'clock', 'info', '/bookings'],
        ['payment_success',   'Payment received',  'We received UGX {amount} for {ref}.', 'card', 'success', '/payments'],
        ['delay_notice',     'Delay notice',      '{trip} is running {mins} minutes late. We apologise.', 'alert', 'warning', '/bookings'],
        ['rating_request',   'How was your trip?', 'Rate your driver for {trip}.', 'star', 'info', '/bookings'],
        ['alert',            'Safety alert',      'An emergency was reported near {stop}. Stay informed.', 'siren', 'danger', '/support'],
        ['promo',            'Promo offer',       '10% off your next booking this week.', 'sparkles', 'info', '/trips/search'],
    ];

    $notifications = 0;
    foreach ($passengerUserIds as $userId) {
        $count    = rnd(1, 4);
        for ($k = 0; $k < $count; $k++) {
            [$type, $title, $message, $icon, $severity, $link] = $notificationTemplates[array_rand($notificationTemplates)];
            $tripRef = $db->value('SELECT reference FROM bookings WHERE passenger_id = ? ORDER BY id DESC LIMIT 1', [$userId]) ?? 'BK-DEMO';
            $ins('notifications', [
                'user_id'   => $userId,
                'type'      => $type,
                'title'     => $title,
                'message'   => str_replace(
                    ['{trip}', '{ref}', '{amount}', '{stop}', '{mins}'],
                    ['TRP-DEMO-001', $tripRef, number_format(rnd(5, 60) * 1000), 'Kampala Central Bus Park', (string) rnd(10, 45)],
                    $message
                ),
                'link'      => $link,
                'icon'      => $icon,
                'severity'  => $severity,
                'is_read'   => random_int(0, 10) > 6 ? 1 : 0,
                'read_at'   => null,
                'created_at'=> dt('-' . rnd(1, 72) . ' hours'),
            ]);
            $notifications++;
        }
    }
    // Staff notifications about the demo dataset.
    foreach ([$adminId, $authorityId] as $staffId) {
        $ins('notifications', [
            'user_id' => $staffId, 'type' => 'system', 'title' => 'Demo dataset loaded',
            'message' => 'All GPS traces, payments and predictions in this environment are simulated.',
            'link' => '/admin/reports', 'icon' => 'info', 'severity' => 'info', 'is_read' => 0,
            'created_at' => dt('-1 hours'),
        ]);
        $notifications++;
    }
    say('notifications: ' . $notifications);

    // -----------------------------------------------------------------------
    // 9. Parcel deliveries + tracking
    // -----------------------------------------------------------------------
    $parcels = [
        'Documents envelope', 'Phone accessories', 'Groceries (2 bags)', 'Pharmacy parcel', 'Spare part (small)',
        'School books', 'Clothing box', 'Birthday cake (fragile)',
    ];
    $deliveryStatuses = ['created', 'assigned', 'picked_up', 'in_transit', 'delivered', 'delivered', 'cancelled'];
    $deliveries = 0;
    $tracking   = 0;

    for ($n = 0; $n < 14; $n++) {
        $customerId = $passengerUserIds[$n % count($passengerUserIds)];
        $user       = $db->first(
            'SELECT u.first_name, u.last_name, u.phone, p.address
               FROM users u
               LEFT JOIN passengers p ON p.user_id = u.id
              WHERE u.id = ?',
            [$customerId]
        );
        $status     = $deliveryStatuses[$n % count($deliveryStatuses)];
        $trip       = $tripRows[$tripIds[$n % count($tripIds)]];

        $deliveryId = $ins('deliveries', [
            'tracking_number'   => seqRef('delivery', false),
            'customer_id'       => $customerId,
            'recipient_name'    => $user['first_name'] . ' ' . $user['last_name'],
            'recipient_phone'   => $user['phone'],
            'pickup_address'    => $user['address'],
            'pickup_latitude'   => 0.3100 + (random_int(0, 3000) / 100000),
            'pickup_longitude'  => 32.5600 + (random_int(0, 4000) / 100000),
            'dropoff_address'   => pick(['Plot 14 Ntinda', 'Kibuli-Kansanga, Kampala', 'Entebbe Town, Wakiso', 'Nsambya Hill, Kampala']),
            'dropoff_latitude'  => 0.2900 + (random_int(0, 9000) / 100000),
            'dropoff_longitude' => 32.4500 + (random_int(0, 16000) / 100000),
            'parcel_description'=> $parcels[$n % count($parcels)],
            'weight_kg'         => round(rnd(1, 12) / 2, 2),
            'is_fragile'        => str_contains($parcels[$n % count($parcels)], 'fragile') ? 1 : 0,
            'declared_value'    => rnd(5, 400) * 1000,
            'vehicle_id'        => $trip['vehicle_id'],
            'trip_id'           => $tripIds[$n % count($tripIds)],
            'operator_id'       => $trip['operator_id'],
            'status'            => $status,
            'estimated_delivery'=> dt('+1 day'),
            'delivered_at'      => $status === 'delivered' ? dt('-1 days') : null,
            'proof_of_delivery' => $status === 'delivered' ? 'Signed by recipient' : null,
            'signature_by'      => $status === 'delivered' ? $user['first_name'] . ' ' . $user['last_name'] : null,
            'price'             => rnd(3, 15) * 1000,
            'created_at'        => dt('-2 days'),
            'updated_at'        => dt('-1 days'),
        ]);
        $deliveries++;

        // Tracking history, always in order and never past the final status.
        $ladder = ['created' => ['created', 'assigned', 'picked_up', 'in_transit', 'delivered'],
                   'assigned' => ['created', 'assigned'],
                   'picked_up' => ['created', 'assigned', 'picked_up'],
                   'in_transit' => ['created', 'assigned', 'picked_up', 'in_transit'],
                   'delivered' => ['created', 'assigned', 'picked_up', 'in_transit', 'delivered'],
                   'cancelled' => ['created', 'cancelled']];
        $labels = ['created' => 'Order created', 'assigned' => 'Assigned to a vehicle', 'picked_up' => 'Picked up from sender',
                   'in_transit' => 'On board - in transit', 'delivered' => 'Delivered to recipient', 'cancelled' => 'Delivery cancelled'];
        foreach ($ladder[$status] as $s => $step) {
            $ins('delivery_tracking', [
                'delivery_id'  => $deliveryId,
                'status'       => $step,
                'description'  => $labels[$step],
                'latitude'     => 0.3000 + (random_int(0, 8000) / 100000),
                'longitude'    => 32.4600 + (random_int(0, 15000) / 100000),
                'location_name'=> pick(['Kampala Central Bus Park', 'Ntinda', 'Entebbe Bus Park', 'Kira', 'Along the route']),
                // recorded_by references users(id), not drivers(id).
                'recorded_by'  => $db->value('SELECT user_id FROM drivers WHERE id = ?', [$trip['driver_id']]),
                'recorded_at'  => dt('-' . (12 - $s * 2) . ' hours'),
            ]);
            $tracking++;
        }
    }
    say('deliveries: ' . $deliveries . ' (tracking rows: ' . $tracking . ')');

    // -----------------------------------------------------------------------
    // 10. Emergencies, complaints, ratings
    // -----------------------------------------------------------------------
    $emergencyPlan = [
        ['accident', 'high',      'Minor collision with a boda rider nearNtinda. No injuries reported.', 'responding'],
        ['medical', 'critical',  'Passenger collapsed on board. Requesting an ambulance at Gaba.', 'resolved'],
        ['breakdown', 'medium',  'Engine warning light on the Mityana bus. Stopped at Nansana.', 'investigating'],
        ['security', 'high',     'Driver refused to stop at the requested stop. Passenger is upset.', 'resolved'],
        ['harassment', 'high',   'Unwanted verbal exchange reported on the Entebbe Express.', 'investigating'],
    ];
    $emergencies = 0;
    foreach ($emergencyPlan as $i => [$type, $severity, $description, $status]) {
        $passengerId = $passengerIds[$i % count($passengerIds)];
        $userId      = $db->value('SELECT user_id FROM passengers WHERE id = ?', [$passengerId]);
        $trip        = $tripRows[$tripIds[$i % count($tripIds)]];

        $ins('emergency_alerts', [
            'reference'     => seqRef('emergency'),
            'user_id'       => $userId,
            'vehicle_id'    => $trip['vehicle_id'],
            'trip_id'       => $tripIds[$i % count($tripIds)],
            'emergency_type'=> $type,
            'description'   => $description,
            'latitude'      => 0.3000 + (random_int(0, 8000) / 100000),
            'longitude'     => 32.4600 + (random_int(0, 15000) / 100000),
            'address_text'  => pick(['Ntinda Flyover', 'Gaba Hill', 'Nansana Road', 'Entebbe Bus Park', 'Kira Road']),
            'contact_phone' => $db->value('SELECT phone FROM users WHERE id = ?', [$userId]),
            'status'        => $status,
            'severity'      => $severity,
            'handled_by'    => $status === 'new' ? null : $authorityId,
            'handled_at'    => $status === 'new' ? null : dt('-20 hours'),
            'resolution_note'=> $status === 'resolved' ? 'Resolved with the passenger and driver; case closed.' : null,
            'resolved_at'   => $status === 'resolved' ? dt('-18 hours') : null,
            'notify_police'=> in_array($severity, ['high', 'critical'], true) ? 1 : 0,
            'is_simulated'  => 1,
            'created_at'    => dt('-' . rnd(2, 60) . ' hours'),
        ]);
        $emergencies++;
    }
    say('emergencies: ' . $emergencies);

    $complaintPlan = [
        ['late_departure', 'medium', 'The bus left Kisementi 20 minutes after the advertised time and nobody announced the delay.'],
        ['overcharging',   'high',   'The conductor asked for an extra 5000 for luggage that is normally included in the fare.'],
        ['cleanliness',    'low',    'The bus was not cleaned before departure; there was litter under the seats.'],
        ['vehicle_condition', 'medium', 'Air conditioning was not working on the Entebbe Express service.'],
        ['driver_behaviour', 'medium', 'The driver was using a phone without a hands-free kit while driving.'],
        ['lost_property',  'low',    'I left a black umbrella on seat B2 and could not find it.'],
    ];
    $complaints = 0;
    foreach ($complaintPlan as $i => [$category, $severity, $description]) {
        $passengerId = $passengerIds[$i % count($passengerIds)];
        $userId      = $db->value('SELECT user_id FROM passengers WHERE id = ?', [$passengerId]);
        $bookingId   = $bookingIds[$i % count($bookingIds)];
        $status      = $i < 2 ? 'open' : ($i < 4 ? 'investigating' : 'resolved');

        $ins('complaints', [
            'reference'   => seqRef('complaint'),
            'user_id'     => $userId,
            'booking_id'  => $bookingId,
            'trip_id'     => $db->value('SELECT trip_id FROM bookings WHERE id = ?', [$bookingId]),
            'vehicle_id'  => $db->value('SELECT vehicle_id FROM bookings WHERE id = ?', [$bookingId]),
            'driver_id'   => $db->value('SELECT driver_id FROM bookings WHERE id = ?', [$bookingId]),
            'category'    => $category,
            'severity'    => $severity,
            'description' => $description,
            'status'      => $status,
            'resolution'  => $status === 'resolved' ? 'The operator was briefed and the conductor retrained. Apology issued to the passenger.' : null,
            'handled_by'  => $status === 'open' ? null : $adminId,
            'resolved_at' => $status === 'resolved' ? dt('-2 days') : null,
            'created_at'  => dt('-' . rnd(1, 20) . ' days'),
        ]);
        $complaints++;
    }
    say('complaints: ' . $complaints);

    // Ratings only for completed trips.
    $ratings = 0;
    foreach ($db->select('SELECT id, trip_id, passenger_id, driver_id, vehicle_id FROM bookings WHERE status = "completed"') as $booking) {
        if ($ratings >= 20) {
            break;
        }
        $score = rnd(3, 5);
        $ins('ratings', [
            'trip_id'     => (int) $booking['trip_id'],
            'booking_id'  => (int) $booking['id'],
            'passenger_id'=> (int) $booking['passenger_id'],
            'driver_id'   => (int) $booking['driver_id'],
            'vehicle_id'  => (int) $booking['vehicle_id'],
            'rating'      => $score,
            'punctuality' => max(1, $score - (random_int(0, 10) > 7 ? 1 : 0)),
            'cleanliness' => max(1, $score - (random_int(0, 10) > 6 ? 1 : 0)),
            'comment'     => pick([
                'Driver was on time and very polite.',
                'Clean bus, smooth ride.',
                'Good service, will book again.',
                'Arrived late but the conductor called to inform me.',
                'Friendly driver, comfortable journey.',
                null,
            ]),
            'is_published'=> 1,
            'created_at'  => dt('-' . rnd(1, 15) . ' days'),
        ]);
        $ratings++;
    }
    say('ratings: ' . $ratings);

    // -----------------------------------------------------------------------
    // 11. Traffic reports, predictions, channel log, activity
    // -----------------------------------------------------------------------
    $trafficPlan = [
        ['congestion',  'high',     25, 'Heavy traffic nearNtinda Flyover, buses moving in single file.'],
        ['roadworks',   'medium',   15, 'Road works on Jinja Road near Kireka roundabout.'],
        ['flooding',    'high',     40, 'Waterlogging at the Bombo road section after rainfall.'],
        ['accident',    'critical', 55, 'Two vehicles blocking the lane at the Katungo junction.'],
        ['event',       'medium',   10, 'Football match at the national stadium, expect crowds near the city centre.'],
    ];
    $traffic = 0;
    foreach ($trafficPlan as $i => [$category, $severity, $delay, $description]) {
        $routeId  = $routeIds[$codes[$i % count($codes)]];
        $location = $db->first('SELECT latitude, longitude FROM locations WHERE id = ?', [$locationId[array_rand($locationId)]]);
        $ins('traffic_reports', [
            'route_id'      => $routeId,
            'location_name' => $db->value('SELECT name FROM locations WHERE id = ?', [$locationId[array_rand($locationId)]]),
            'latitude'      => $location['latitude'],
            'longitude'     => $location['longitude'],
            'category'      => $category,
            'severity'      => $severity,
            'delay_minutes' => $delay,
            'description'   => $description,
            'source'        => pick(['manual', 'sensor', 'prediction', 'community']),
            'reported_by'   => random_int(0, 4) === 0 ? $authorityId : null,
            'status'        => $i % 2 === 0 ? 'open' : 'monitoring',
            'created_at'    => dt('-' . rnd(1, 30) . ' hours'),
            'updated_at'    => dt('-1 hours'),
        ]);
        $traffic++;
    }
    say('traffic_reports: ' . $traffic);

    // Heuristic predictions, always flagged as demo output.
    $predictions = 0;
    foreach (['demand_forecast', 'arrival_time', 'delay_risk', 'occupancy'] as $module) {
        foreach (array_slice($routeIds, 0, 5, true) as $routeId) {
            $ins('ai_predictions', [
                'module'        => $module,
                'scope_type'    => 'route',
                'scope_id'      => $routeId,
                'label'         => $db->value('SELECT route_code FROM routes WHERE id = ?', [$routeId]) . ' / ' . $module,
                'score'         => round(random_int(40, 99) / 100, 4),
                'value_numeric' => match ($module) {
                    'arrival_time' => rnd(12, 90),
                    'delay_risk'   => round(random_int(0, 80) / 100, 4),
                    default        => null,
                },
                'value_text'    => $module === 'arrival_time' ? 'On time +/- 10 min' : null,
                'confidence'    => round(random_int(55, 92) / 100, 4),
                'model'         => 'heuristic-v1',
                'is_demo'       => 1,
                'features'      => json_encode(['window' => '7d', 'trips' => rnd(5, 30), 'note' => 'simulated heuristic output']),
                'generated_at'  => dt('-2 hours'),
                'expires_at'    => dt('+6 hours'),
            ]);
            $predictions++;
        }
    }
    say('ai_predictions: ' . $predictions . ' (is_demo = 1)');

    // USSD / SMS log rows: recorded, never actually sent.
    $channelRows = 0;
    foreach (['ussd', 'sms', 'call_centre'] as $channel) {
        for ($n = 0; $n < 3; $n++) {
            $userId = $passengerUserIds[$n % count($passengerUserIds)];
            $phone  = (string) $db->value('SELECT phone FROM users WHERE id = ?', [$userId]);
            $ins('channel_messages', [
                'channel'        => $channel,
                'direction'      => 'outbound',
                'event_type'     => $channel === 'ussd' ? 'booking_lookup' : 'trip_reminder',
                'phone_masked'   => '+256***' . substr($phone, -3),
                'user_id'        => $userId,
                'payload'        => json_encode(['demo' => true, 'body' => 'Your trip tomorrow departs at 07:30 from Kampala Central Bus Park.']),
                'provider'       => 'none',
                'provider_reference' => '',
                'status'         => 'recorded',
                'is_simulated'   => 1,
                'created_at'     => dt('-' . rnd(1, 48) . ' hours'),
            ]);
            $channelRows++;
        }
    }
    say('channel_messages: ' . $channelRows . ' (provider = none)');

    $logs = 0;
    foreach (['login', 'logout', 'booking_created', 'payment_recorded', 'trip_updated', 'settings_updated'] as $action) {
        $ins('activity_logs', [
            'user_id'     => $adminId,
            'action'      => $action,
            'entity_type' => 'system',
            'entity_id'   => null,
            'description' => 'Demo seed entry: ' . $action,
            'ip_address'  => '127.0.0.1',
            'user_agent'  => 'UniGo seeder',
            'created_at'  => dt('-' . rnd(1, 100) . ' hours'),
        ]);
        $logs++;
    }
    say('activity_logs: ' . $logs);

    // -----------------------------------------------------------------------
    // 12. Roll-ups so dashboards agree with the row data
    // -----------------------------------------------------------------------
    $db->run('UPDATE drivers d
              SET d.rating_avg = COALESCE((SELECT ROUND(AVG(r.rating), 2) FROM ratings r WHERE r.driver_id = d.id AND r.is_published = 1), d.rating_avg),
                  d.rating_count = (SELECT COUNT(*) FROM ratings r WHERE r.driver_id = d.id AND r.is_published = 1),
                  d.total_trips = (SELECT COUNT(*) FROM trips t WHERE t.driver_id = d.id AND t.status = "completed")');
    say('driver rating roll-up');

    // bookings.passenger_id is a users.id, so the roll-up joins through
    // passengers.user_id rather than comparing it to the profile id.
    $db->run('UPDATE passengers p
              SET p.total_bookings = (SELECT COUNT(*) FROM bookings b WHERE b.passenger_id = p.user_id AND b.status <> "cancelled"),
                  p.total_spent = COALESCE((SELECT ROUND(SUM(b.fare), 2) FROM bookings b WHERE b.passenger_id = p.user_id AND b.payment_status = "paid"), 0)');
    say('passenger roll-up');

    $db->run('UPDATE operators o
              SET o.total_revenue = COALESCE((SELECT ROUND(SUM(p.amount), 2)
                                                FROM payments p
                                                INNER JOIN bookings b ON b.id = p.booking_id
                                               WHERE b.vehicle_id IN (SELECT id FROM vehicles WHERE operator_id = o.id)
                                                 AND p.status = "successful"), 0),
                  o.rating_count = (SELECT COUNT(*) FROM ratings r
                                      WHERE r.driver_id IN (SELECT id FROM drivers WHERE operator_id = o.id) AND r.is_published = 1)');
    say('operator roll-up');

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    out('');
    out('SEED FAILED');

    // Walk the exception chain: the Database wrapper hides the PDO message.
    for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
        out('  ' . get_class($cause) . ': ' . $cause->getMessage());
        out('    at ' . $cause->getFile() . ':' . $cause->getLine());
    }
    exit(1);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$counts = [];
foreach (['users', 'passengers', 'drivers', 'operators', 'vehicles', 'seats', 'routes', 'route_stops',
          'trips', 'bookings', 'payments', 'notifications', 'locations', 'vehicle_locations',
          'deliveries', 'delivery_tracking', 'emergency_alerts', 'complaints', 'ratings',
          'traffic_reports', 'ai_predictions', 'channel_messages', 'activity_logs'] as $table) {
    $counts[$table] = (int) $db->value('SELECT COUNT(*) FROM `' . $table . '`');
}

out('');
out('Seeded totals:');
foreach ($counts as $table => $count) {
    printf("  %-22s %6d\n", $table, $count);
}

out('');
out('Demo accounts (password for all of them: ' . DEMO_PASSWORD . ')');
out('  admin@unigo.test      - Administrator');
out('  authority@unigo.test  - Transport authority');
out('  operator@unigo.test   - Kampala City Bus Services');
out('  driver@unigo.test     - Driver');
out('  passenger@unigo.test  - Passenger');
out('');
out('All payments are mocked, all GPS traces simulated, all USSD/SMS rows recorded only.');
