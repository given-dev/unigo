<?php
/**
 * UniGo - Booking model.
 *
 * Concurrency model
 * -----------------
 * A seat is protected twice:
 *   1. `SELECT ... FOR UPDATE` locks the trip row for the duration of the
 *      transaction, so two passengers cannot interleave their availability
 *      check and insert.
 *   2. The UNIQUE index on (trip_id, seat_number) is the final authority. If
 *      two requests ever did race, the second INSERT raises a duplicate key
 *      error which we translate into a friendly message.
 * The whole flow (availability check -> booking insert -> payment ledger ->
 * notification) runs inside a single transaction.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\ActivityLog;
use App\Core\Config;
use App\Core\ConflictException;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\ReferenceGenerator;

final class BookingModel extends BaseModel
{
    protected string $table = 'bookings';

    private const JOINS = 'INNER JOIN trips t ON t.id = b.trip_id
                          INNER JOIN routes r ON r.id = t.route_id
                          INNER JOIN vehicles v ON v.id = b.vehicle_id
                          LEFT JOIN operators o ON o.id = t.operator_id
                          LEFT JOIN drivers d ON d.id = b.driver_id
                          LEFT JOIN users du ON du.id = d.user_id';

    private const SELECT = 'b.*, t.trip_code, t.departure_time, t.arrival_time, t.status AS trip_status,
                            r.name AS route_name, r.route_code, r.origin_name, r.destination_name,
                            r.origin_latitude, r.origin_longitude, r.destination_latitude, r.destination_longitude,
                            v.registration_number, v.vehicle_type, v.make, v.model,
                            o.company_name,
                            d.id AS driver_id, du.first_name AS driver_first_name, du.last_name AS driver_last_name,
                            du.phone AS driver_phone,
                            pu.first_name AS passenger_first_name, pu.last_name AS passenger_last_name,
                            pu.phone AS passenger_phone';

    // ------------------------------------------------------------------
    // Seat map
    // ------------------------------------------------------------------

    /**
     * Seat map for one trip: every physical seat plus its booking state.
     * One query, no N+1.
     *
     * @return array{trip:array<string,mixed>,seats:array<int,array<string,mixed>>,bookings:array<int,array<string,mixed>>}
     */
    public function seatMap(int $tripId): array
    {
        $trip = (new TripModel())->findDetailed($tripId);
        if (!$trip) {
            throw new \App\Core\NotFoundException('That trip is no longer available.');
        }

        $seats = $this->db->select(
            "SELECT s.id, s.seat_number, s.seat_type, s.row_number, s.is_active,
                    b.id AS booking_id, b.status AS booking_status, b.passenger_id,
                    pu.first_name, pu.last_name
             FROM seats s
             LEFT JOIN bookings b
                    ON b.trip_id = ? AND b.seat_number = s.seat_number
                   AND b.status IN ('pending','confirmed','completed')
             LEFT JOIN users pu ON pu.id = b.passenger_id
             WHERE s.vehicle_id = ?
             ORDER BY (s.seat_number + 0) ASC",
            [$tripId, (int) $trip['vehicle_id']]
        );

        $bookings = $this->db->select(
            'SELECT id, reference, seat_number, status, passenger_id FROM bookings
             WHERE trip_id = ? AND status IN ("pending","confirmed","completed")',
            [$tripId]
        );

        return ['trip' => $trip, 'seats' => $seats, 'bookings' => $bookings];
    }

    /**
     * Bookable seats for the current passenger (never expose other people's
     * identity in the public API response).
     *
     * @return array<int,array<string,mixed>>
     */
    public function bookableSeats(int $tripId, int $passengerUserId): array
    {
        $result = $this->seatMap($tripId);
        $out = [];
        foreach ($result['seats'] as $seat) {
            $out[] = [
                'seat_number' => $seat['seat_number'],
                'seat_type'   => $seat['seat_type'],
                'row_number'  => (int) $seat['row_number'],
                'is_available' => (bool) $seat['is_active'] && $seat['booking_id'] === null,
                'is_mine'     => $seat['passenger_id'] !== null && (int) $seat['passenger_id'] === $passengerUserId,
                'status'      => $seat['booking_status'],
            ];
        }

        // Vehicles without a seat layout (e.g. boda / taxi) get numbered slots
        // derived from capacity.
        if ($out === []) {
            $trip = $result['trip'];
            $reservations = array_column($result['bookings'], null, 'seat_number');
            for ($i = 1; $i <= (int) $trip['capacity']; $i++) {
                $label = (string) $i;
                $booking = $reservations[$label] ?? null;
                $out[] = [
                    'seat_number'  => $label,
                    'seat_type'    => 'standard',
                    'row_number'   => (int) ceil($i / 4),
                    'is_available' => $booking === null,
                    'is_mine'      => $booking !== null && (int) $booking['passenger_id'] === $passengerUserId,
                    'status'       => $booking['status'] ?? null,
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /**
     * Create a booking.
     *
     * @param array{
     *   trip_id:int, seat_number:string, passenger_id:int,
     *   from_stop_id?:int, to_stop_id?:int, pickup_point?:string,
     *   payment_method?:string, notes?:string, is_simulated?:bool
     * } $data
     * @return array{booking:array<string,mixed>,payment:?array<string,mixed>}
     */
    public function createBooking(array $data, ?int $actorId = null): array
    {
        $tripId = (int) $data['trip_id'];
        $passengerId = (int) $data['passenger_id'];
        $seat = strtoupper(trim((string) $data['seat_number']));
        $payMethod = $data['payment_method'] ?? (is_demo_mode() ? 'mobile_money' : 'cash');
        if (!in_array($payMethod, array_column(PaymentService::methods(), 'value'), true)) {
            throw new ValidationException('Choose an available payment method. Online payments are not configured.');
        }
        $isSimulated = is_demo_mode() ? (int) ($data['is_simulated'] ?? 0) : 0;

        $db = $this->db;

        return $db->transaction(function () use ($db, $tripId, $passengerId, $seat, $payMethod, $isSimulated, $data, $actorId): array {

            // 1. Lock the trip row so availability cannot change mid-transaction.
            $trip = $db->first(
                'SELECT t.*, r.name AS route_name, r.base_fare, r.status AS route_status, v.status AS vehicle_status, o.approval_status, ou.status AS operator_user_status, v.capacity, v.vehicle_type,
                        v.registration_number
                 FROM trips t
                 INNER JOIN routes r ON r.id = t.route_id
                 INNER JOIN vehicles v ON v.id = t.vehicle_id
                 LEFT JOIN operators o ON o.id = t.operator_id
                 LEFT JOIN users ou ON ou.id = o.user_id
                 WHERE t.id = ?
                 FOR UPDATE',
                [$tripId]
            );

            if (!$trip) {
                throw new \App\Core\NotFoundException('That trip is no longer available.');
            }
            if ($trip['route_status'] !== 'active' || !in_array($trip['vehicle_status'], ['active','on_trip'], true) || ($trip['operator_id'] && ($trip['approval_status'] !== 'approved' || $trip['operator_user_status'] !== 'active'))) {
                throw new ConflictException('This trip is currently unavailable for booking.');
            }
            if ($trip['status'] !== 'scheduled' && $trip['status'] !== 'boarding') {
                throw new ConflictException('This trip is not open for booking.');
            }
            if (strtotime((string) $trip['departure_time']) <= time()) {
                throw new ConflictException('This trip has already departed.');
            }

            // 2. One seat per passenger per trip.
            $existing = $db->first(
                "SELECT reference FROM bookings
                 WHERE trip_id = ? AND passenger_id = ? AND status IN ('pending','confirmed') LIMIT 1",
                [$tripId, $passengerId]
            );
            if ($existing) {
                throw new ConflictException('You already have booking ' . $existing['reference'] . ' on this trip.');
            }

            // 3. Validate the seat exists (or is SHARED for shared rides).
            $capacity = (int) $trip['capacity'];
            $isShared = $trip['vehicle_type'] === 'shared_ride';
            if (!$isShared) {
                $seatRow = $db->first(
                    'SELECT * FROM seats WHERE vehicle_id = ? AND seat_number = ? AND is_active = 1 LIMIT 1',
                    [(int) $trip['vehicle_id'], $seat]
                );
                if (!$seatRow) {
                    // fall back to the capacity check for vehicles without a layout
                    if ($db->exists('SELECT 1 FROM seats WHERE vehicle_id = ? LIMIT 1', [(int) $trip['vehicle_id']]) || !ctype_digit($seat) || (string) (int) $seat !== $seat || (int) $seat < 1 || (int) $seat > $capacity) {
                        throw new ValidationException('Seat ' . e($seat) . ' does not exist on this vehicle.');
                    }
                }
            }

            if ($isShared && (!ctype_digit($seat) || (string) (int) $seat !== $seat || (int) $seat < 1 || (int) $seat > $capacity)) {
                throw new ValidationException('Choose an available seat.');
            }

            // 4. Capacity / double-booking guard.
            $booked = $db->count(
                "SELECT COUNT(*) FROM bookings WHERE trip_id = ? AND status IN ('pending','confirmed','completed')",
                [$tripId]
            );
            if ($booked >= (int) $trip['seats_total']) {
                throw new ConflictException('No seats are currently available on this trip.');
            }

            $clash = $db->first(
                "SELECT reference FROM bookings
                 WHERE trip_id = ? AND seat_number = ? AND status IN ('pending','confirmed','completed')
                 LIMIT 1",
                [$tripId, $seat]
            );
            if ($clash) {
                throw new ConflictException('That seat has already been booked. Please choose another seat.');
            }

            // Validate both stops against this route and charge the travelled segment.
            $fare = (float) $trip['fare'];
            $from = null;
            $to = null;
            foreach (['from_stop_id', 'to_stop_id'] as $key) {
                if (!empty($data[$key])) {
                    $stop = $db->first('SELECT * FROM route_stops WHERE id = ? AND route_id = ?', [(int) $data[$key], (int) $trip['route_id']]);
                    if (!$stop) {
                        throw new ValidationException('Choose stops on this route.');
                    }
                    if ($key === 'from_stop_id') {
                        if (!(bool) $stop['is_pickup_point']) {
                            throw new ValidationException('This stop does not allow boarding.');
                        }
                        $from = $stop;
                    } else {
                        $to = $stop;
                    }
                }
            }
            if ($from && $to && (int) $from['stop_order'] >= (int) $to['stop_order']) {
                throw new ValidationException('The drop-off stop must come after the boarding stop.');
            }
            if ($from || $to) {
                $startFare = $from ? (float) $from['fare_from_origin'] : 0;
                $endFare = $to ? (float) $to['fare_from_origin'] : $fare;
                $fare = $endFare - $startFare;
            }
            if ($fare <= 0) {
                throw new ValidationException('This route segment does not have a valid fare.');
            }

            $reference = ReferenceGenerator::generate('booking');

            try {
                $bookingId = $this->create([
                    'reference'       => $reference,
                    'trip_id'         => $tripId,
                    'passenger_id'    => $passengerId,
                    'vehicle_id'      => (int) $trip['vehicle_id'],
                    'driver_id'       => $trip['driver_id'],
                    'seat_number'     => $seat,
                    'from_stop_id'    => $data['from_stop_id'] ?? null,
                    'to_stop_id'      => $data['to_stop_id'] ?? null,
                    'pickup_point'    => $data['pickup_point'] ?? '',
                    'status'          => 'confirmed',
                    'fare'            => $fare,
                    'currency'        => (string) Config::get('app.currency', 'UGX'),
                    'payment_status'  => $payMethod === 'cash' ? 'unpaid' : 'pending',
                    'booking_channel' => $this->channel(),
                    'is_simulated'    => $isSimulated,
                    'confirmed_at'    => date('Y-m-d H:i:s'),
                    'notes'           => $data['notes'] ?? '',
                ]);
            } catch (\PDOException $e) {
                // 23000 = duplicate key -> the UNIQUE(trip_id, seat_number) index fired.
                if ($e->getCode() === '23000') {
                    throw new ConflictException('That seat has already been booked. Please choose another seat.');
                }
                throw $e;
            }

            // 6. Payment (mock gateway) inside the same transaction.
            $payment = null;
            if ($payMethod !== 'cash') {
                $payment = PaymentService::charge(
                    $passengerId,
                    $fare,
                    $payMethod,
                    ['booking_id' => $bookingId],
                    $actorId
                );
                if (($payment['status'] ?? '') !== 'successful') {
                    throw new ConflictException((string) ($payment['message'] ?? 'Payment failed. Please try another method.'));
                }
                if (($payment['status'] ?? '') === 'successful') {
                    $db->update('bookings', ['payment_status' => 'paid'], 'id = ?', [$bookingId]);

                }
            }

            $db->run('UPDATE passengers SET total_bookings = total_bookings + 1 WHERE user_id = ?', [$passengerId]);
            NotificationService::push($passengerId, 'booking', 'Booking confirmed', 'Your booking ' . $reference . ' is confirmed.', '/bookings/' . $bookingId);
            $booking = $this->findDetailed($bookingId);

            ActivityLog::record(
                ActivityLog::BOOKING_CREATED,
                $bookingId,
                'booking',
                'Booking ' . $reference . ' created for trip ' . $trip['trip_code'],
                $passengerId
            );

            return ['booking' => $booking, 'payment' => $payment];
        });
    }

    // ------------------------------------------------------------------
    // Cancellation
    // ------------------------------------------------------------------

    public function cancel(int $bookingId, int $actorUserId, string $reason = '', bool $isAdmin = false): array
    {
        return $this->db->transaction(function () use ($bookingId, $actorUserId, $reason, $isAdmin): array {
            $booking = $this->db->first(
                'SELECT b.*, t.departure_time, t.trip_code, t.status AS trip_status
                 FROM bookings b INNER JOIN trips t ON t.id = b.trip_id
                 WHERE b.id = ? FOR UPDATE',
                [$bookingId]
            );

            if (!$booking) {
                throw new \App\Core\NotFoundException('Booking not found.');
            }

            // Authorisation: owner, admin, or the operator of this trip.
            if (!$isAdmin) {
                $allowed = (int) $booking['passenger_id'] === $actorUserId;
                if (!$allowed && \App\Core\Auth::isOperator()) {
                    $allowed = $this->db->exists(
                        'SELECT 1 FROM trips t WHERE t.id = ? AND t.operator_id = ?',
                        [$bookingId === 0 ? 0 : (int) $booking['trip_id'], (int) \App\Core\Auth::operatorId()]
                    );
                }
                if (!$allowed) {
                    throw new \App\Core\AuthorizationException('You cannot cancel this booking.');
                }
            }

            if (in_array($booking['status'], ['cancelled', 'completed'], true)) {
                throw new ConflictException('This booking is already ' . $booking['status'] . '.');
            }
            if ($booking['trip_status'] === 'in_transit' || $booking['trip_status'] === 'completed') {
                throw new ConflictException('This trip has already started, so the booking can no longer be cancelled online.');
            }

            if (!$isAdmin) {
                $window = (int) Config::get('domain.cancellation_window_min', 30);
                $minutesLeft = (int) round((strtotime((string) $booking['departure_time']) - time()) / 60);
                if ($minutesLeft < $window) {
                    throw new ConflictException(
                        'Online cancellation closes ' . $window . ' minutes before departure. Please contact support.'
                    );
                }
            }

            $this->updateById($bookingId, [
                'status'        => 'cancelled',
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason !== '' ? $reason : 'Cancelled by the passenger',
            ]);

            // Refund a successful online payment.
            if ($booking['payment_status'] === 'paid') {
                PaymentService::refundForBooking($bookingId);
            }

            NotificationService::bookingCancelled(
                (int) $booking['passenger_id'],
                (string) $booking['reference'],
                (string) $booking['trip_code']
            );

            ActivityLog::record(
                ActivityLog::BOOKING_CANCELLED,
                $bookingId,
                'booking',
                'Booking ' . $booking['reference'] . ' cancelled',
                $actorUserId
            );

            return $this->findDetailed($bookingId) ?? [];
        });
    }

    // ------------------------------------------------------------------
    // Queries
    // ------------------------------------------------------------------

    public function findDetailed(int $bookingId): ?array
    {
        return $this->db->first(
            'SELECT ' . self::SELECT . ' FROM bookings b ' . self::JOINS . '
             LEFT JOIN users pu ON pu.id = b.passenger_id
             WHERE b.id = ? LIMIT 1',
            [$bookingId]
        );
    }

    public function findByReference(string $reference): ?array
    {
        return $this->db->first(
            'SELECT ' . self::SELECT . ' FROM bookings b ' . self::JOINS . '
             LEFT JOIN users pu ON pu.id = b.passenger_id
             WHERE b.reference = ? LIMIT 1',
            [$reference]
        );
    }

    /**
     * @param array{passenger_id?:int,status?:string,trip_id?:int,search?:string,date?:string,operator_id?:int} $filters
     */
    public function paginateBookings(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['passenger_id'])) {
            $where[] = 'b.passenger_id = ?';
            $params[] = (int) $filters['passenger_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'b.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['trip_id'])) {
            $where[] = 'b.trip_id = ?';
            $params[] = (int) $filters['trip_id'];
        }
        if (!empty($filters['operator_id'])) {
            $where[] = 't.operator_id = ?';
            $params[] = (int) $filters['operator_id'];
        }
        if (!empty($filters['date'])) {
            $where[] = 'DATE(t.departure_time) = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(b.reference LIKE ? OR b.seat_number LIKE ? OR pu.first_name LIKE ? OR pu.last_name LIKE ? OR v.registration_number LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return $this->paginateQuery([
            'select' => self::SELECT,
            'from'   => 'bookings b',
            'joins'  => self::JOINS . ' LEFT JOIN users pu ON pu.id = b.passenger_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'b.booked_at DESC',
        ], $page, $perPage);
    }

    /** Bookings on a trip - only ever called by the trip owner/operator/admin. */
    public function passengersForTrip(int $tripId): array
    {
        return $this->db->select(
            'SELECT b.id, b.reference, b.seat_number, b.status, b.fare, b.payment_status, b.booked_at,
                    u.first_name, u.last_name, u.phone, u.email
             FROM bookings b
             INNER JOIN users u ON u.id = b.passenger_id
             WHERE b.trip_id = ?
             ORDER BY b.seat_number ASC',
            [$tripId]
        );
    }

    public function markCompleted(int $tripId): int
    {
        return $this->db->update(
            'bookings',
            ['status' => 'completed'],
            "trip_id = ? AND status IN ('pending','confirmed')",
            [$tripId]
        );
    }

    public function countToday(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM bookings WHERE DATE(booked_at) = CURDATE()');
    }

    public function dailySeries(int $days = 14): array
    {
        return $this->dailySeriesQuery('bookings', 'booked_at', [
            'total'     => 'COUNT(*)',
            'cancelled' => "SUM(status = 'cancelled')",
            'completed' => "SUM(status = 'completed')",
        ], $days);
    }

    public function revenueToday(): float
    {
        return (float) $this->db->value(
            "SELECT COALESCE(SUM(fare),0) FROM bookings
             WHERE payment_status = 'paid' AND DATE(booked_at) = CURDATE()"
        );
    }

    /** Which booking channel passengers used (analytics). */
    public function channelBreakdown(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return $this->db->select(
            'SELECT booking_channel AS label, COUNT(*) AS total FROM bookings
             WHERE booked_at >= (CURDATE() - INTERVAL ? DAY)
             GROUP BY booking_channel ORDER BY total DESC',
            [$days]
        );
    }

    private function channel(): string
    {
        if (Request::isCli()) {
            return 'admin';
        }
        return Request::instance()->isApiPath() ? 'web' : 'web';
    }
}
