<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ActivityLog, Auth, AuthorizationException, ConflictException, Database, NotFoundException};
use App\Models\PaymentModel;

/** Cash receipts record an actual handover confirmed by staff, never a gateway charge. */
final class CashPaymentService
{
    private static function booking(int $id, bool $refund): array
    {
        $db = Database::instance();
        $row = $db->first('SELECT b.*, t.operator_id, t.driver_id AS assigned_driver, t.status AS trip_status FROM bookings b JOIN trips t ON t.id=b.trip_id WHERE b.id=? FOR UPDATE', [$id]);
        if (!$row) throw new NotFoundException('Booking not found.');
        $allowed = Auth::isAdmin()
            || (Auth::isOperator() && Auth::operatorId() !== null && (int) $row['operator_id'] === Auth::operatorId())
            || (!$refund && Auth::isDriver() && Auth::driverId() !== null && (int) $row['assigned_driver'] === Auth::driverId());
        if (!$allowed) throw new AuthorizationException('You cannot record cash for this booking.');
        return $row;
    }

    public static function collect(int $bookingId): array
    {
        return Database::instance()->transaction(function ($db) use ($bookingId): array {
            $booking = self::booking($bookingId, false);
            if (!in_array($booking['status'], ['confirmed', 'completed'], true) || $booking['trip_status'] === 'cancelled') throw new ConflictException('Only active or completed bookings can be paid.');
            if ($booking['payment_status'] !== 'unpaid') throw new ConflictException('This booking is already paid or has another payment in progress.');
            $reference = ReferenceGenerator::generate('payment');
            $id = $db->insert('payments', [
                'reference' => $reference, 'booking_id' => $bookingId, 'user_id' => $booking['passenger_id'],
                'amount' => $booking['fare'], 'currency' => $booking['currency'], 'method' => 'cash',
                'provider' => 'cash', 'provider_reference' => $reference, 'status' => 'successful',
                'is_mock' => 0, 'initiated_by' => Auth::id(), 'completed_at' => date('Y-m-d H:i:s'),
            ]);
            $db->update('bookings', ['payment_status' => 'paid'], 'id=?', [$bookingId]);
            $db->run('UPDATE passengers SET total_spent=total_spent+? WHERE user_id=?', [$booking['fare'], $booking['passenger_id']]);
            if ($booking['operator_id']) $db->run('UPDATE operators SET total_revenue=total_revenue+? WHERE id=?', [$booking['fare'], $booking['operator_id']]);
            NotificationService::paymentSuccessful((int) $booking['passenger_id'], $reference, (float) $booking['fare']);
            ActivityLog::record(ActivityLog::PAYMENT_RECORDED, $id, 'payment', 'Cash received for ' . $booking['reference'], Auth::id());
            return (new PaymentModel())->find($id) ?? [];
        });
    }

    public static function refund(int $bookingId): void
    {
        Database::instance()->transaction(function ($db) use ($bookingId): void {
            $booking = self::booking($bookingId, true);
            if ($booking['status'] !== 'cancelled' || $booking['payment_status'] !== 'paid') throw new ConflictException('Only a cancelled, paid booking can receive a cash refund.');
            $payments = $db->select("SELECT * FROM payments WHERE booking_id=? AND method='cash' AND is_mock=0 AND status='successful' FOR UPDATE", [$bookingId]);
            if (!$payments) throw new ConflictException('No cash receipt is available for this booking.');
            foreach ($payments as $payment) {
                $db->update('payments', ['status' => 'refunded', 'refunded_amount' => $payment['amount']], 'id=?', [$payment['id']]);
                $db->run('UPDATE passengers SET total_spent=GREATEST(0,total_spent-?) WHERE user_id=?', [$payment['amount'], $booking['passenger_id']]);
                if ($booking['operator_id']) $db->run('UPDATE operators SET total_revenue=GREATEST(0,total_revenue-?) WHERE id=?', [$payment['amount'], $booking['operator_id']]);
                ActivityLog::record('payment.cash_refunded', (int) $payment['id'], 'payment', 'Cash returned for ' . $booking['reference'], Auth::id());
            }
            $db->update('bookings', ['payment_status' => 'refunded'], 'id=?', [$bookingId]);
            NotificationService::push((int) $booking['passenger_id'], 'payment', 'Cash refund recorded', 'Staff confirmed the cash refund for booking ' . $booking['reference'] . '.', '/payments');
        });
    }
}
