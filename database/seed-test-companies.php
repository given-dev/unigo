<?php
/**
 * UniGo - focused test-company seeder.
 *
 * Creates three ready-to-use test companies so every role has a small, real
 * dataset to work with:
 *
 *   1. Pearl Test Bus Services   bus   Kampala -> Jinja    cash booking, seat 1
 *   2. City Test Taxi Services   taxi  Kampala -> Entebbe  cash booking, seat 1
 *   3. Swift Test Logistics      truck Kampala -> Mukono   test parcel
 *
 * Each company gets an operator, a driver, a passenger/customer and an
 * authority account, one vehicle (with a seat map), one route (with origin and
 * destination stops), a scheduled trip and the record that proves the flow
 * (a cash booking for the bus and taxi, a parcel for the logistics company).
 *
 * Every account uses the reserved @unigo.test domain, so this only runs when
 * UNIGO_DEMO_MODE=1 (never in production) and against a disposable database.
 *
 * Usage:
 *   php database/seed-test-companies.php          # insert (refuses if present)
 *   php database/seed-test-companies.php --fresh  # remove old rows, then insert
 *
 * All accounts share one password, printed on completion: UniGo@2026
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("The test-company seeder may only be run from the command line.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/src/bootstrap.php';

if (!is_demo_mode()) {
    fwrite(STDERR, "Demo seeding is disabled. Use UNIGO_DEMO_MODE=1 only on a disposable test database.\n");
    exit(1);
}

use App\Core\Auth;
use App\Core\Database;
use App\Services\ReferenceGenerator;

const TEST_PASSWORD = 'UniGo@2026';

/** Accounts created by this seeder (used to detect and clean a previous run). */
const TEST_EMAILS = [
    'bus.operator@unigo.test', 'bus.driver@unigo.test', 'bus.passenger@unigo.test', 'bus.authority@unigo.test',
    'taxi.operator@unigo.test', 'taxi.driver@unigo.test', 'taxi.passenger@unigo.test', 'taxi.authority@unigo.test',
    'logistics.operator@unigo.test', 'logistics.driver@unigo.test', 'logistics.customer@unigo.test', 'logistics.authority@unigo.test',
];

const TEST_OPERATOR_LICENCES = ['TEST-BUS-001', 'TEST-TAXI-001', 'TEST-LOG-001'];
const TEST_DRIVER_LICENCES   = ['TEST-DL-B001', 'TEST-DL-T001', 'TEST-DL-L001'];
const TEST_VEHICLE_REGS      = ['TEST-BUS-01', 'TEST-TAXI-01', 'TEST-TRK-01'];
const TEST_ROUTE_CODES       = ['TEST-KLA-JIN', 'TEST-KLA-EBB', 'TEST-KLA-MUK'];

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function say(string $step): void
{
    printf("  %-34s %s\n", $step, 'ok');
}

function stamp(): string
{
    return date('Y-m-d H:i:s');
}

/** Comma separated list of ? placeholders for an IN (...) clause. */
function qmarks(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

$fresh = in_array('--fresh', $argv, true);
$db    = Database::instance();

// ---------------------------------------------------------------------------
// Guard
// ---------------------------------------------------------------------------
$existing = $db->count(
    'SELECT COUNT(*) FROM users WHERE email IN (' . qmarks(TEST_EMAILS) . ')',
    TEST_EMAILS
);
if ($existing > 0 && !$fresh) {
    out('The test companies already exist (' . $existing . ' accounts). Nothing to do.');
    out('Re-run with --fresh to replace the three test companies.');
    exit(0);
}

/**
 * Remove any previous copy of the three test companies.
 *
 * Rows are deleted children first and FOREIGN_KEY_CHECKS is disabled for the
 * duration so the order is not load bearing, matching the demo seeder.
 */
function purgeTestData(Database $db): void
{
    $emails = TEST_EMAILS;
    $licences = TEST_OPERATOR_LICENCES;
    $regs = TEST_VEHICLE_REGS;
    $codes = TEST_ROUTE_CODES;
    $dls = TEST_DRIVER_LICENCES;

    $usersByEmail   = 'SELECT id FROM users WHERE email IN (' . qmarks($emails) . ')';
    $operatorsByLic = 'SELECT id FROM operators WHERE license_number IN (' . qmarks($licences) . ')';
    $driversByLic   = 'SELECT id FROM drivers WHERE license_number IN (' . qmarks($dls) . ')';
    $vehiclesByReg  = 'SELECT id FROM vehicles WHERE registration_number IN (' . qmarks($regs) . ')';
    $routesByCode   = 'SELECT id FROM routes WHERE route_code IN (' . qmarks($codes) . ')';

    $deletes = [
        ['DELETE FROM delivery_tracking WHERE delivery_id IN (
              SELECT id FROM deliveries
               WHERE operator_id IN (' . $operatorsByLic . ') OR customer_id IN (' . $usersByEmail . '))',
            array_merge($licences, $emails)],

        ['DELETE FROM deliveries
            WHERE operator_id IN (' . $operatorsByLic . ')
               OR customer_id IN (' . $usersByEmail . ')
               OR vehicle_id IN (' . $vehiclesByReg . ')',
            array_merge($licences, $emails, $regs)],

        ['DELETE FROM payments
            WHERE user_id IN (' . $usersByEmail . ')
               OR booking_id IN (SELECT id FROM bookings WHERE passenger_id IN (' . $usersByEmail . '))',
            array_merge($emails, $emails)],

        ['DELETE FROM notifications WHERE user_id IN (' . $usersByEmail . ')', $emails],

        ['DELETE FROM bookings
            WHERE passenger_id IN (' . $usersByEmail . ')
               OR trip_id IN (SELECT id FROM trips WHERE operator_id IN (' . $operatorsByLic . '))',
            array_merge($emails, $licences)],

        ['DELETE FROM vehicle_locations WHERE vehicle_id IN (' . $vehiclesByReg . ')', $regs],

        ['DELETE FROM trips
            WHERE operator_id IN (' . $operatorsByLic . ')
               OR vehicle_id IN (' . $vehiclesByReg . ')
               OR route_id IN (' . $routesByCode . ')',
            array_merge($licences, $regs, $codes)],

        ['DELETE FROM seats WHERE vehicle_id IN (' . $vehiclesByReg . ')', $regs],

        ['DELETE FROM vehicles
            WHERE operator_id IN (' . $operatorsByLic . ') OR registration_number IN (' . qmarks($regs) . ')',
            array_merge($licences, $regs)],

        ['DELETE FROM route_stops WHERE route_id IN (' . $routesByCode . ')', $codes],

        ['DELETE FROM routes
            WHERE operator_id IN (' . $operatorsByLic . ') OR route_code IN (' . qmarks($codes) . ')',
            array_merge($licences, $codes)],

        ['DELETE FROM operator_drivers
            WHERE operator_id IN (' . $operatorsByLic . ') OR driver_id IN (' . $driversByLic . ')',
            array_merge($licences, $dls)],

        ['DELETE FROM drivers
            WHERE operator_id IN (' . $operatorsByLic . ') OR license_number IN (' . qmarks($dls) . ')',
            array_merge($licences, $dls)],

        ['DELETE FROM operators WHERE license_number IN (' . qmarks($licences) . ')', $licences],

        ['DELETE FROM passengers WHERE user_id IN (' . $usersByEmail . ')', $emails],

        ['DELETE FROM user_roles WHERE user_id IN (' . $usersByEmail . ')', $emails],

        ['DELETE FROM users WHERE email IN (' . qmarks($emails) . ')', $emails],
    ];

    $db->run('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($deletes as [$sql, $params]) {
        $db->run($sql, $params);
    }
    $db->run('SET FOREIGN_KEY_CHECKS = 1');
}

if ($fresh) {
    purgeTestData($db);
    say('removed previous test companies');
}

// ---------------------------------------------------------------------------
// Plan - the three test companies
// ---------------------------------------------------------------------------
$plan = [
    [
        'label'     => 'bus',
        'company'   => ['name' => 'Pearl Test Bus Services', 'licence' => 'TEST-BUS-001', 'address' => 'Kampala, Uganda',
                        'description' => 'Test commuter bus company used for end-to-end UniGo verification.'],
        'operator'  => ['email' => 'bus.operator@unigo.test',  'first' => 'Daniel', 'last' => 'Kato',    'phone' => '+256700000101'],
        'authority' => ['email' => 'bus.authority@unigo.test', 'first' => 'Grace',  'last' => 'Nambi',   'phone' => '+256700000104'],
        'driver'    => ['email' => 'bus.driver@unigo.test',    'first' => 'Peter',  'last' => 'Ssemanda','phone' => '+256700000102',
                        'licence' => 'TEST-DL-B001', 'licence_type' => 'bus'],
        'passenger' => ['email' => 'bus.passenger@unigo.test', 'first' => 'Sarah',  'last' => 'Namutebi','phone' => '+256700000103',
                        'address' => 'Kampala', 'city' => 'Kampala', 'district' => 'Central'],
        'vehicle'   => ['registration' => 'TEST-BUS-01',  'type' => 'bus',   'capacity' => 40,
                        'make' => 'Isuzu', 'model' => 'LT134', 'colour' => 'White'],
        'route'     => ['code' => 'TEST-KLA-JIN', 'name' => 'Kampala - Jinja',
                        'origin' => 'Kampala', 'destination' => 'Jinja',
                        'origin_latitude' => 0.311700, 'origin_longitude' => 32.576700,
                        'destination_latitude' => 0.424400, 'destination_longitude' => 33.204100,
                        'distance_km' => 80.0, 'duration_minutes' => 120, 'fare' => 20000.0, 'colour' => '#2563EB'],
        'trip'      => ['departure' => '2026-10-10 08:00:00', 'arrival' => '2026-10-10 10:00:00'],
        'booking'   => ['seat' => '1', 'payment' => 'cash'],
    ],
    [
        'label'     => 'taxi',
        'company'   => ['name' => 'City Test Taxi Services', 'licence' => 'TEST-TAXI-001', 'address' => 'Entebbe, Uganda',
                        'description' => 'Test taxi company used for end-to-end UniGo verification.'],
        'operator'  => ['email' => 'taxi.operator@unigo.test',  'first' => 'Rebecca', 'last' => 'Achieng',   'phone' => '+256700000201'],
        'authority' => ['email' => 'taxi.authority@unigo.test', 'first' => 'David',   'last' => 'Ssenyonga','phone' => '+256700000204'],
        'driver'    => ['email' => 'taxi.driver@unigo.test',    'first' => 'James',   'last' => 'Okello',   'phone' => '+256700000202',
                        'licence' => 'TEST-DL-T001', 'licence_type' => 'psv'],
        'passenger' => ['email' => 'taxi.passenger@unigo.test', 'first' => 'Brian',   'last' => 'Mugisha',  'phone' => '+256700000203',
                        'address' => 'Entebbe', 'city' => 'Entebbe', 'district' => 'Wakiso'],
        'vehicle'   => ['registration' => 'TEST-TAXI-01', 'type' => 'taxi',  'capacity' => 4,
                        'make' => 'Toyota', 'model' => 'Hiace', 'colour' => 'Yellow'],
        'route'     => ['code' => 'TEST-KLA-EBB', 'name' => 'Kampala - Entebbe',
                        'origin' => 'Kampala', 'destination' => 'Entebbe',
                        'origin_latitude' => 0.311700, 'origin_longitude' => 32.576700,
                        'destination_latitude' => 0.051200, 'destination_longitude' => 32.462000,
                        'distance_km' => 40.0, 'duration_minutes' => 60, 'fare' => 30000.0, 'colour' => '#059669'],
        'trip'      => ['departure' => '2026-10-10 09:00:00', 'arrival' => '2026-10-10 10:00:00'],
        'booking'   => ['seat' => '1', 'payment' => 'cash'],
    ],
    [
        'label'     => 'logistics',
        'company'   => ['name' => 'Swift Test Logistics', 'licence' => 'TEST-LOG-001', 'address' => 'Mukono, Uganda',
                        'description' => 'Test logistics company used for end-to-end UniGo parcel verification.'],
        'operator'  => ['email' => 'logistics.operator@unigo.test',  'first' => 'Michael', 'last' => 'Waiswa', 'phone' => '+256700000301'],
        'authority' => ['email' => 'logistics.authority@unigo.test', 'first' => 'Mary',    'last' => 'Atim',   'phone' => '+256700000304'],
        'driver'    => ['email' => 'logistics.driver@unigo.test',    'first' => 'Joseph',  'last' => 'Ouma',   'phone' => '+256700000302',
                        'licence' => 'TEST-DL-L001', 'licence_type' => 'heavy'],
        'passenger' => ['email' => 'logistics.customer@unigo.test',  'first' => 'Agnes',   'last' => 'Nakato', 'phone' => '+256700000303',
                        'address' => 'Mukono', 'city' => 'Mukono', 'district' => 'Mukono'],
        'vehicle'   => ['registration' => 'TEST-TRK-01',  'type' => 'truck', 'capacity' => 2,
                        'make' => 'Isuzu', 'model' => 'FVR', 'colour' => 'Blue'],
        'route'     => ['code' => 'TEST-KLA-MUK', 'name' => 'Kampala - Mukono',
                        'origin' => 'Kampala', 'destination' => 'Mukono',
                        'origin_latitude' => 0.311700, 'origin_longitude' => 32.576700,
                        'destination_latitude' => 0.353300, 'destination_longitude' => 32.755300,
                        'distance_km' => 21.0, 'duration_minutes' => 90, 'fare' => 15000.0, 'colour' => '#EA580C'],
        'trip'      => ['departure' => '2026-10-10 07:00:00', 'arrival' => '2026-10-10 08:30:00'],
        'parcel'    => ['description' => 'Box of exercise books', 'weight_kg' => 10.0, 'declared_value' => 100000.0,
                        'price' => 15000.0, 'is_fragile' => 0, 'pickup' => 'Kampala Central', 'dropoff' => 'Mukono Town',
                        'recipient_name' => 'Henry Musoke', 'recipient_phone' => '+256700000305'],
    ],
];

// ---------------------------------------------------------------------------
// Insert
// ---------------------------------------------------------------------------
$roleId = [];
foreach ($db->select('SELECT id, slug FROM roles') as $role) {
    $roleId[$role['slug']] = (int) $role['id'];
}
foreach (['operator', 'driver', 'passenger', 'authority'] as $needed) {
    if (!isset($roleId[$needed])) {
        fwrite(STDERR, "The '$needed' role is missing. Run the installer first.\n");
        exit(1);
    }
}

$hash = Auth::hashPassword(TEST_PASSWORD);

$insertUser = static function (string $email, string $first, string $last, string $phone, string $role) use ($db, $hash, $roleId): int {
    $id = $db->insert('users', [
        'email'                => $email,
        'password_hash'        => $hash,
        'first_name'           => $first,
        'last_name'            => $last,
        'phone'                => $phone,
        'gender'               => 'undisclosed',
        'status'               => 'active',
        'email_verified_at'    => stamp(),
        'password_changed_at'  => stamp(),
        'must_change_password' => 0,
        'created_at'           => stamp(),
    ]);
    $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId[$role]]);
    return $id;
};

try {
    $db->beginTransaction();

    $created = [];

    foreach ($plan as $c) {
        // 1. Accounts -----------------------------------------------------
        $operatorUserId  = $insertUser($c['operator']['email'],  $c['operator']['first'],  $c['operator']['last'],  $c['operator']['phone'],  'operator');
        $driverUserId    = $insertUser($c['driver']['email'],    $c['driver']['first'],    $c['driver']['last'],    $c['driver']['phone'],    'driver');
        $passengerUserId = $insertUser($c['passenger']['email'], $c['passenger']['first'], $c['passenger']['last'], $c['passenger']['phone'], 'passenger');
        $insertUser($c['authority']['email'], $c['authority']['first'], $c['authority']['last'], $c['authority']['phone'], 'authority');

        // 2. Company profile ---------------------------------------------
        $operatorId = $db->insert('operators', [
            'user_id'         => $operatorUserId,
            'company_name'    => $c['company']['name'],
            'license_number'  => $c['company']['licence'],
            'approval_status' => 'approved',
            'approved_at'     => stamp(),
            'contact_email'   => $c['operator']['email'],
            'contact_phone'   => $c['operator']['phone'],
            'address'         => $c['company']['address'],
            'description'     => $c['company']['description'],
            'created_at'      => stamp(),
        ]);

        // 3. Driver profile + company roster -----------------------------
        $driverId = $db->insert('drivers', [
            'user_id'          => $driverUserId,
            'operator_id'      => $operatorId,
            'license_number'   => $c['driver']['licence'],
            'license_type'     => $c['driver']['licence_type'],
            'license_expiry'   => date('Y-m-d', strtotime('+2 years')),
            'experience_years' => 5,
            'status'           => 'available',
            'created_at'       => stamp(),
        ]);
        $db->insert('operator_drivers', [
            'operator_id' => $operatorId,
            'driver_id'   => $driverId,
            'joined_at'   => stamp(),
            'is_primary'  => 1,
        ]);

        // 4. Passenger / customer profile --------------------------------
        $db->insert('passengers', [
            'user_id'           => $passengerUserId,
            'address'           => $c['passenger']['address'],
            'city'              => $c['passenger']['city'],
            'district'          => $c['passenger']['district'],
            'preferred_payment' => 'cash',
            'created_at'        => stamp(),
        ]);

        // 5. Route + stops -------------------------------------------------
        $routeId = $db->insert('routes', [
            'route_code'            => $c['route']['code'],
            'name'                  => $c['route']['name'],
            'origin_name'           => $c['route']['origin'],
            'origin_latitude'       => $c['route']['origin_latitude'],
            'origin_longitude'      => $c['route']['origin_longitude'],
            'destination_name'      => $c['route']['destination'],
            'destination_latitude'  => $c['route']['destination_latitude'],
            'destination_longitude' => $c['route']['destination_longitude'],
            'distance_km'           => $c['route']['distance_km'],
            'duration_minutes'      => $c['route']['duration_minutes'],
            'base_fare'             => $c['route']['fare'],
            'operator_id'           => $operatorId,
            'status'                => 'active',
            'colour'                => $c['route']['colour'],
            'description'           => $c['route']['name'] . ' test route.',
            'created_at'            => stamp(),
            'updated_at'            => stamp(),
        ]);

        $originStopId = $db->insert('route_stops', [
            'route_id'            => $routeId,
            'stop_order'          => 1,
            'stop_name'           => $c['route']['origin'],
            'latitude'            => $c['route']['origin_latitude'],
            'longitude'           => $c['route']['origin_longitude'],
            'minutes_from_origin' => 0,
            'fare_from_origin'    => 0,
            'is_pickup_point'     => 1,
        ]);
        $destStopId = $db->insert('route_stops', [
            'route_id'            => $routeId,
            'stop_order'          => 2,
            'stop_name'           => $c['route']['destination'],
            'latitude'            => $c['route']['destination_latitude'],
            'longitude'           => $c['route']['destination_longitude'],
            'minutes_from_origin' => $c['route']['duration_minutes'],
            'fare_from_origin'    => $c['route']['fare'],
            'is_pickup_point'     => 1,
        ]);

        // 6. Vehicle + seat map (numeric seats, as the staff form creates) -
        $vehicleId = $db->insert('vehicles', [
            'registration_number' => $c['vehicle']['registration'],
            'vehicle_type'        => $c['vehicle']['type'],
            'make'                => $c['vehicle']['make'],
            'model'               => $c['vehicle']['model'],
            'year'                => (int) date('Y'),
            'colour'              => $c['vehicle']['colour'],
            'capacity'            => $c['vehicle']['capacity'],
            'operator_id'         => $operatorId,
            'driver_id'           => $driverId,
            'home_route_id'       => $routeId,
            'status'              => 'active',
            'gps_enabled'         => 1,
            'gps_device_id'       => 'GPS-' . strtoupper(substr(md5((string) $c['vehicle']['registration']), 0, 8)),
            'insurance_expiry'    => date('Y-m-d', strtotime('+1 year')),
            'inspection_status'   => 'valid',
            'last_inspection'     => date('Y-m-d'),
            'odometer_km'         => 0,
            'created_at'          => stamp(),
        ]);
        for ($n = 1; $n <= (int) $c['vehicle']['capacity']; $n++) {
            $db->insert('seats', [
                'vehicle_id'  => $vehicleId,
                'seat_number' => (string) $n,
                'seat_type'   => 'standard',
                'row_number'  => (int) ceil($n / 4),
                'is_active'   => 1,
            ]);
        }

        // 7. Scheduled trip -----------------------------------------------
        $tripId = $db->insert('trips', [
            'trip_code'      => ReferenceGenerator::generate('trip'),
            'route_id'       => $routeId,
            'vehicle_id'     => $vehicleId,
            'driver_id'      => $driverId,
            'operator_id'    => $operatorId,
            'departure_time' => $c['trip']['departure'],
            'arrival_time'   => $c['trip']['arrival'],
            'boarding_opens' => date('Y-m-d H:i:s', strtotime($c['trip']['departure'] . ' -20 minutes')),
            'status'         => 'scheduled',
            'seats_total'    => (int) $c['vehicle']['capacity'],
            'fare'           => $c['route']['fare'],
            'currency'       => 'UGX',
            'created_at'     => stamp(),
        ]);

        // 8. Booking (bus / taxi) or parcel (logistics) -------------------
        if (isset($c['booking'])) {
            $db->insert('bookings', [
                'reference'      => ReferenceGenerator::generate('booking'),
                'trip_id'        => $tripId,
                'passenger_id'   => $passengerUserId,
                'vehicle_id'     => $vehicleId,
                'driver_id'      => $driverId,
                'seat_number'    => $c['booking']['seat'],
                'from_stop_id'   => $originStopId,
                'to_stop_id'     => $destStopId,
                'pickup_point'   => $c['route']['origin'],
                'status'         => 'confirmed',
                'fare'           => $c['route']['fare'],
                'currency'       => 'UGX',
                // Cash: the seat is reserved now and staff record the fare later.
                'payment_status' => 'unpaid',
                'booking_channel'=> 'admin',
                'is_simulated'   => 1,
                'booked_at'      => stamp(),
                'confirmed_at'   => stamp(),
            ]);
        }

        if (isset($c['parcel'])) {
            $parcel = $c['parcel'];
            $deliveryId = $db->insert('deliveries', [
                'tracking_number'    => ReferenceGenerator::generate('delivery'),
                'customer_id'        => $passengerUserId,
                'recipient_name'     => $parcel['recipient_name'],
                'recipient_phone'    => $parcel['recipient_phone'],
                'pickup_address'     => $parcel['pickup'],
                'pickup_latitude'    => $c['route']['origin_latitude'],
                'pickup_longitude'   => $c['route']['origin_longitude'],
                'dropoff_address'    => $parcel['dropoff'],
                'dropoff_latitude'   => $c['route']['destination_latitude'],
                'dropoff_longitude'  => $c['route']['destination_longitude'],
                'parcel_description' => $parcel['description'],
                'weight_kg'          => $parcel['weight_kg'],
                'is_fragile'         => (int) $parcel['is_fragile'],
                'declared_value'     => $parcel['declared_value'],
                'vehicle_id'         => $vehicleId,
                'trip_id'            => $tripId,
                'operator_id'        => $operatorId,
                'status'             => 'created',
                'estimated_delivery' => $c['trip']['arrival'],
                'price'              => $parcel['price'],
                'created_at'         => stamp(),
                'updated_at'         => stamp(),
            ]);
            $db->insert('delivery_tracking', [
                'delivery_id'   => $deliveryId,
                'status'        => 'created',
                'description'   => 'Delivery request created',
                'latitude'      => $c['route']['origin_latitude'],
                'longitude'     => $c['route']['origin_longitude'],
                'location_name' => $parcel['pickup'],
                'recorded_by'   => $driverUserId,
                'recorded_at'   => stamp(),
            ]);
        }

        $created[] = [
            'label'   => $c['label'],
            'company' => $c['company']['name'],
            'operator'=> $c['operator']['email'],
            'driver'  => $c['driver']['email'],
            'passenger' => $c['passenger']['email'],
            'authority' => $c['authority']['email'],
            'route'   => $c['route']['code'],
            'vehicle' => $c['vehicle']['registration'],
        ];
        say($c['label'] . ': ' . $c['company']['name']);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    out('');
    out('SEED FAILED');
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
foreach (['users', 'operators', 'drivers', 'passengers', 'vehicles', 'seats', 'routes', 'route_stops',
          'trips', 'bookings', 'deliveries', 'delivery_tracking'] as $table) {
    $counts[$table] = (int) $db->value('SELECT COUNT(*) FROM `' . $table . '`');
}

out('');
out('Test companies created (password for every account: ' . TEST_PASSWORD . ')');
foreach ($created as $c) {
    out('');
    out('  ' . $c['company'] . '  [' . $c['vehicle'] . ' / ' . $c['route'] . ']');
    out('    operator   ' . $c['operator']);
    out('    driver     ' . $c['driver']);
    out('    passenger  ' . $c['passenger']);
    out('    authority  ' . $c['authority']);
}

out('');
out('Table totals now:');
foreach ($counts as $table => $count) {
    printf("  %-22s %6d\n", $table, $count);
}
out('');
out('Routes depart 10 October 2026; bookings are cash and unpaid until staff record the fare.');
