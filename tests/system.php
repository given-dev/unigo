<?php
/** Domain regressions. Run against a disposable seeded database. */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\{Auth, Database, ConflictException, ValidationException};
use App\Models\{BookingModel, ComplaintModel, DeliveryModel, EmergencyModel, StatsModel, TripModel, UserModel};
use App\Services\{GpsService, MockPaymentGateway, NotificationService, PaymentGatewayInterface, PaymentService};

$db = Database::instance();
$checks = 0;
$failures = [];
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $callback, string $exception = ValidationException::class): void
{
    try { $callback(); } catch (Throwable $e) {
        check($e instanceof $exception, 'Wrong exception: ' . get_class($e) . ': ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Invalid operation was accepted.');
}
function scenario(string $name, callable $callback): void
{
    global $db, $failures;
    $db->beginTransaction();
    try { $callback(); }
    catch (Throwable $e) { $failures[] = $name . ': ' . $e->getMessage(); }
    finally {
        $db->rollback();
        // Also clean up a leaked transaction so later scenarios stay independent.
        if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
        PaymentService::useGateway(new MockPaymentGateway());
    }
}
function fixture(): array
{
    global $db;
    $route = $db->first('SELECT * FROM routes WHERE status = "active" ORDER BY id LIMIT 1');
    $driverUser = (new UserModel())->createUser([
        'email' => 'driver-reg-' . bin2hex(random_bytes(5)) . '@example.test',
        'password_hash' => Auth::hashPassword('Testing@2026'), 'first_name' => 'Regression', 'last_name' => 'Driver',
    ], ['driver'], ['license_number' => 'REG-' . bin2hex(random_bytes(5)), 'operator_id' => $route['operator_id'], 'status' => 'available'], 'drivers');
    $driver = $db->first('SELECT * FROM drivers WHERE user_id = ?', [$driverUser]);
    $vehicle = $db->insert('vehicles', [
        'registration_number' => 'REG-' . bin2hex(random_bytes(4)), 'vehicle_type' => 'bus',
        'capacity' => 4, 'operator_id' => $route['operator_id'], 'driver_id' => $driver['id'], 'status' => 'active',
    ]);
    $trip = $db->insert('trips', [
        'trip_code' => 'REG-' . bin2hex(random_bytes(4)), 'vehicle_id' => $vehicle,
        'route_id' => $route['id'], 'operator_id' => $route['operator_id'], 'driver_id' => $driver['id'],
        'departure_time' => date('Y-m-d H:i:s', strtotime('+60 days')),
        'arrival_time' => date('Y-m-d H:i:s', strtotime('+60 days +1 hour')),
        'seats_total' => 4, 'fare' => 15000, 'status' => 'scheduled',
    ]);
    $user = (new UserModel())->createUser([
        'email' => 'reg-' . bin2hex(random_bytes(5)) . '@example.test',
        'password_hash' => Auth::hashPassword('Testing@2026'), 'first_name' => 'Regression', 'last_name' => 'Passenger',
    ], ['passenger'], ['city' => 'Kampala'], 'passengers');
    return compact('route', 'driver', 'vehicle', 'trip', 'user');
}
function parcel(int $user): array
{
    return (new DeliveryModel())->createDelivery([
        'recipient_name' => 'Test Recipient', 'recipient_phone' => '+256700123456',
        'pickup_address' => 'Kampala', 'dropoff_address' => 'Entebbe',
        'parcel_description' => 'Books', 'weight_kg' => 1, 'declared_value' => 0,
        'pickup_latitude' => 0, 'pickup_longitude' => 32.5,
        'dropoff_latitude' => null, 'dropoff_longitude' => null,
    ], $user);
}

// A caught inner failure must roll back the outer transaction and release it.
$marker = 'tx-' . bin2hex(random_bytes(5));
try {
    $db->transaction(function () use ($db, $marker): void {
        $db->insert('system_settings', ['setting_key' => $marker, 'setting_value' => 'before']);
        try { $db->transaction(function (): void { throw new RuntimeException('inner failure'); }); }
        catch (RuntimeException $e) {}
        $db->update('system_settings', ['setting_value' => 'after'], 'setting_key = ?', [$marker]);
    });
    $failures[] = 'Nested transactions: silently accepted a rollback-only commit.';
} catch (RuntimeException $e) {}
try {
    check(!$db->inTransaction(), 'Nested transactions: failed outer commit leaked a transaction.');
    check(!$db->exists('SELECT 1 FROM system_settings WHERE setting_key = ?', [$marker]), 'Nested writes were persisted.');
} catch (Throwable $e) { $failures[] = $e->getMessage(); }
finally { if ($db->pdo()->inTransaction()) $db->pdo()->rollBack(); }
$db->rollback();
scenario('Successful nested transactions', function () use ($db): void {
    check($db->transaction(fn () => $db->transaction(fn () => 42)) === 42, 'Nested return value lost.');
    check($db->inTransaction(), 'A nested commit released its caller transaction.');
});

scenario('Booking completion', function () use ($db): void {
    $f = fixture();
    $booking = (new BookingModel())->createBooking(['trip_id' => $f['trip'], 'passenger_id' => $f['user'], 'seat_number' => '1', 'payment_method' => 'cash']);
    check((new BookingModel())->markCompleted($f['trip']) === 1, 'Completion must return the number of bookings.');
    check($db->value('SELECT status FROM bookings WHERE id = ?', [$booking['booking']['id']]) === 'completed', 'Booking did not complete.');
});
scenario('Seat maps without a physical layout', function () use ($db): void {
    $f = fixture(); $model = new BookingModel();
    $model->createBooking(['trip_id' => $f['trip'], 'passenger_id' => $f['user'], 'seat_number' => '1', 'payment_method' => 'cash']);
    $seat = $model->bookableSeats($f['trip'], $f['user'])[0];
    check($seat['is_mine'] && !$seat['is_available'], 'Numbered capacity slots must recognize the owner booking.');
    $db->run('UPDATE bookings SET status = "completed" WHERE trip_id = ?', [$f['trip']]);
    check(!$model->bookableSeats($f['trip'], $f['user'])[0]['is_available'], 'Completed bookings still occupy their numbered slots.');
});
scenario('Scoped dashboards', function () use ($db): void {
    $f = fixture(); $stats = new StatsModel();
    $driver = $stats->workspaceHeadline('driver', (int) $f['driver']['id']);
    check((int) $driver['total_trips'] === 1 && (int) $driver['active_trips'] === 0, 'Driver dashboard includes another driver trips.');
    $operatorId = (int) $f['route']['operator_id'];
    $operator = $stats->workspaceHeadline('operator', $operatorId);
    check((int) $operator['total_drivers'] === $db->count('SELECT COUNT(*) FROM drivers WHERE operator_id = ?', [$operatorId]), 'Operator dashboard includes another fleet drivers.');
    check((float) $operator['today_revenue'] === (float) $db->value('SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN trips t ON t.id = b.trip_id WHERE t.operator_id = ? AND p.status = "successful" AND DATE(p.created_at) = CURDATE()', [$operatorId]), 'Operator dashboard revenue is not scoped.');
    check((int) $stats->workspaceHeadline('operator', 0)['active_vehicles'] === 0, 'A missing profile exposes the whole fleet.');
});
scenario('Long running passenger trips', function () use ($db): void {
    $f = fixture();
    (new BookingModel())->createBooking(['trip_id' => $f['trip'], 'passenger_id' => $f['user'], 'seat_number' => '1', 'payment_method' => 'cash']);
    $db->update('trips', ['status' => 'in_transit', 'departure_time' => date('Y-m-d H:i:s', strtotime('-6 hours'))], 'id = ?', [$f['trip']]);
    check(count((new TripModel())->forPassenger($f['user'])) === 1, 'Long journeys disappear from passenger tracking.');
});
scenario('Tracking outside the global map limit', function () use ($db): void {
    $f = fixture();
    $db->insert('vehicle_locations', ['vehicle_id' => $f['vehicle'], 'latitude' => 0, 'longitude' => 32,
        'recorded_at' => date('Y-m-d H:i:s', strtotime('-1 minute')), 'source' => 'driver_phone', 'is_simulated' => 0]);
    $prefix = 'GPS-' . bin2hex(random_bytes(3));
    for ($i = 0; $i < 201; $i++) {
        $vehicle = $db->insert('vehicles', ['registration_number' => $prefix . '-' . $i, 'vehicle_type' => 'bus', 'capacity' => 4, 'status' => 'active']);
        $db->insert('vehicle_locations', ['vehicle_id' => $vehicle, 'latitude' => 1, 'longitude' => 33, 'recorded_at' => '2099-01-01 00:00:00']);
    }
    $map = (new ReflectionMethod(App\Controllers\TrackingController::class, 'buildMap'))
        ->invoke(new App\Controllers\TrackingController(), (new TripModel())->findDetailed($f['trip']));
    check(count($map['vehicles']) === 1 && $map['vehicles'][0]['id'] === $f['vehicle'], 'A large fleet hides the passenger vehicle.');
    check(GpsService::latestPositions([]) === [], 'An empty vehicle scope exposes the entire fleet.');
});
scenario('Boarding reversal', function () use ($db): void {
    $f = fixture(); $model = new TripModel();
    $db->update('drivers', ['status' => 'available'], 'id = ?', [$f['driver']['id']]);
    $model->updateStatus($f['trip'], 'boarding');
    $model->updateStatus($f['trip'], 'scheduled');
    check($db->value('SELECT status FROM vehicles WHERE id = ?', [$f['vehicle']]) === 'active', 'Reversing boarding must release the vehicle.');
    check($db->value('SELECT status FROM drivers WHERE id = ?', [$f['driver']['id']]) === 'available', 'Reversing boarding must release the driver.');
});
scenario('Concurrent active trips', function () use ($db): void {
    $f = fixture();
    $other = $db->first('SELECT * FROM trips WHERE id = ?', [$f['trip']]);
    unset($other['id']); $other['trip_code'] .= '-2';
    $second = $db->insert('trips', $other);
    (new TripModel())->updateStatus($f['trip'], 'boarding');
    rejects(fn () => (new TripModel())->updateStatus($second, 'boarding'), ConflictException::class);
});
scenario('Cancellation preserves busy fleet', function () use ($db): void {
    $f = fixture();
    $other = $db->first('SELECT * FROM trips WHERE id = ?', [$f['trip']]);
    unset($other['id']); $other['trip_code'] .= '-2';
    $second = $db->insert('trips', $other);
    (new TripModel())->updateStatus($f['trip'], 'boarding');
    (new TripModel())->updateStatus($second, 'cancelled');
    check($db->value('SELECT status FROM vehicles WHERE id = ?', [$f['vehicle']]) === 'on_trip', 'Cancelling another trip must not release a busy vehicle.');
    check($db->value('SELECT status FROM drivers WHERE id = ?', [$f['driver']['id']]) === 'on_trip', 'Cancelling another trip must not release a busy driver.');
});
scenario('Cancellation preserves suspended fleet', function () use ($db): void {
    $f = fixture();
    $db->update('vehicles', ['status' => 'suspended'], 'id = ?', [$f['vehicle']]);
    $db->update('drivers', ['status' => 'suspended'], 'id = ?', [$f['driver']['id']]);
    (new TripModel())->updateStatus($f['trip'], 'cancelled');
    check($db->value('SELECT status FROM vehicles WHERE id = ?', [$f['vehicle']]) === 'suspended', 'Cancellation reactivated a suspended vehicle.');
    check($db->value('SELECT status FROM drivers WHERE id = ?', [$f['driver']['id']]) === 'suspended', 'Cancellation reactivated a suspended driver.');
});
scenario('Parcel assignment atomicity', function () use ($db): void {
    $f = fixture(); $model = new DeliveryModel(); $parcel = parcel($f['user']);
    $model->updateStatus((int) $parcel['id'], 'cancelled');
    rejects(fn () => $model->assign((int) $parcel['id'], $f['vehicle'], null, (int) $f['route']['operator_id'], null), ConflictException::class);
    check($model->find((int) $parcel['id'])['vehicle_id'] === null, 'A rejected assignment changed the vehicle.');
});
scenario('Parcel assignment requires a vehicle', function (): void {
    $f = fixture(); $model = new DeliveryModel(); $parcel = parcel($f['user']);
    rejects(fn () => $model->updateStatus((int) $parcel['id'], 'assigned'));
});
scenario('Parcel coordinates', function (): void {
    $f = fixture(); $parcel = parcel($f['user']);
    check($parcel['pickup_latitude'] !== null && (float) $parcel['pickup_latitude'] === 0.0, 'Equator coordinates were discarded.');
});
scenario('Parcel validation', function (): void {
    $f = fixture();
    rejects(fn () => (new DeliveryModel())->createDelivery([
        'recipient_name' => 'Recipient', 'recipient_phone' => 'invalid', 'pickup_address' => 'Kampala',
        'dropoff_address' => 'Entebbe', 'parcel_description' => 'Books', 'weight_kg' => -1, 'declared_value' => -10,
        'pickup_latitude' => null, 'pickup_longitude' => null, 'dropoff_latitude' => null, 'dropoff_longitude' => null,
    ], $f['user']));
});
scenario('Complaint validation', function (): void {
    $f = fixture();
    rejects(fn () => (new ComplaintModel())->openComplaint(['category' => 'other', 'description' => '   '], $f['user']));
});
scenario('Inactive operator cannot take bookings', function () use ($db): void {
    $f = fixture();
    $operatorUser = $db->value('SELECT user_id FROM operators WHERE id = ?', [$f['route']['operator_id']]);
    $db->update('users', ['status' => 'suspended'], 'id = ?', [$operatorUser]);
    rejects(fn () => (new BookingModel())->createBooking(['trip_id' => $f['trip'], 'passenger_id' => $f['user'], 'seat_number' => '1', 'payment_method' => 'cash']), ConflictException::class);
});
scenario('Failed refunds preserve the ledger', function () use ($db): void {
    $f = fixture();
    $result = (new BookingModel())->createBooking(['trip_id' => $f['trip'], 'passenger_id' => $f['user'], 'seat_number' => '1', 'payment_method' => 'card']);
    PaymentService::useGateway(new class implements PaymentGatewayInterface {
        public function name(): string { return 'test'; }
        public function label(): string { return 'Test'; }
        public function charge(array $request): array { throw new RuntimeException('Unused'); }
        public function refund(array $request): array { return ['status' => 'failed', 'message' => 'Declined']; }
        public function verifyCallback(array $payload): bool { return false; }
    });
    rejects(fn () => PaymentService::refundForBooking((int) $result['booking']['id']), ConflictException::class);
    check($db->value('SELECT status FROM payments WHERE id = ?', [$result['payment']['id']]) === 'successful', 'A failed refund changed the payment ledger.');
    check($db->value('SELECT payment_status FROM bookings WHERE id = ?', [$result['booking']['id']]) === 'paid', 'A failed refund changed booking payment status.');
});
scenario('Driver assignment notifications', function () use ($db): void {
    $f = fixture();
    NotificationService::tripAssigned((int) $f['driver']['id'], $f['trip'], 'Test route', date('Y-m-d H:i:s', strtotime('+1 day')));
    $notification = $db->first('SELECT user_id, link FROM notifications ORDER BY id DESC LIMIT 1');
    check((int) $notification['user_id'] === (int) $f['driver']['user_id'], 'Trip assignment was sent to a profile ID instead of its user account.');
    check($notification['link'] === '/driver/trips?trip=' . $f['trip'], 'Assigned trip notification points at a missing page.');
});
scenario('Passenger boarding notifications', function () use ($db): void {
    $f = fixture();
    (new BookingModel())->createBooking(['trip_id' => $f['trip'], 'passenger_id' => $f['user'], 'seat_number' => '1', 'payment_method' => 'cash']);
    NotificationService::tripBoarding($f['trip'], 'Test trip', 'Test route', date('Y-m-d H:i:s', strtotime('+1 day')));
    check($db->value('SELECT link FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$f['user']]) === '/trips/' . $f['trip'], 'Boarding notification points at a missing page.');
});
scenario('Operator revenue aggregation', function () use ($db): void {
    $operator = $db->first("SELECT id FROM operators WHERE approval_status = 'approved' ORDER BY id LIMIT 1");
    $expected = $db->first('SELECT COUNT(*) AS bookings, COALESCE(SUM(CASE WHEN b.payment_status = "paid" AND b.status <> "cancelled" THEN b.fare ELSE 0 END),0) AS revenue FROM bookings b JOIN trips t ON t.id = b.trip_id WHERE t.operator_id = ? AND b.booked_at >= (CURDATE() - INTERVAL 30 DAY)', [$operator['id']]);
    $rows = (new StatsModel())->operatorPerformance(50);
    $actual = array_values(array_filter($rows, fn ($row) => (int) $row['id'] === (int) $operator['id']))[0];
    check((int) $actual['bookings'] === (int) $expected['bookings'], 'Joining the fleet multiplied operator bookings.');
    check((float) $actual['revenue'] === (float) $expected['revenue'], 'Operator revenue is inflated or includes unpaid fares.');
});
scenario('Emergency coordinates validation', function (): void {
    $f = fixture();
    rejects(fn () => (new EmergencyModel())->raise($f['user'], ['latitude' => 91, 'longitude' => 32]));
});
scenario('Emergency responder trip assignment', function () use ($db): void {
    $f = fixture();
    $db->update('vehicles', ['driver_id' => null], 'id = ?', [$f['vehicle']]);
    (new BookingModel())->createBooking(['trip_id' => $f['trip'], 'passenger_id' => $f['user'], 'seat_number' => '1', 'payment_method' => 'cash']);
    $db->update('trips', ['status' => 'in_transit'], 'id = ?', [$f['trip']]);
    (new EmergencyModel())->raise($f['user'], ['description' => 'Test emergency']);
    check($db->value('SELECT link FROM notifications WHERE user_id = ? AND type = "emergency" ORDER BY id DESC LIMIT 1', [$f['driver']['user_id']]) === '/driver/sos', 'Emergency missed the trip driver or points at a missing page.');
    $authority = $db->value('SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = "authority" LIMIT 1');
    check($db->value('SELECT link FROM notifications WHERE user_id = ? AND type = "emergency" ORDER BY id DESC LIMIT 1', [$authority]) === '/authority/emergencies', 'Authority emergency notification points at an admin-only page.');
});
scenario('Emergency ignores distant future bookings', function (): void {
    $f = fixture();
    (new BookingModel())->createBooking(['trip_id' => $f['trip'], 'passenger_id' => $f['user'], 'seat_number' => '1', 'payment_method' => 'cash']);
    $alert = (new EmergencyModel())->raise($f['user'], ['description' => 'Off-trip emergency']);
    check($alert['trip_id'] === null && $alert['vehicle_id'] === null, 'An unrelated future booking was attached to the emergency.');
});

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "System regression checks passed: $checks\n";
