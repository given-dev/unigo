<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth, Database, ActivityLog, AuthorizationException, ConflictException};

final class CashPaymentService
{
    /** Staff confirms money actually collected or returned, never an online charge. */
    public static function record(int $bookingId, bool $refund = false): array
    {
        return Database::instance()->transaction(function () use ($bookingId, $refund): array {
            $db = Database::instance();
            $booking = $db->first('SELECT b.*,t.operator_id,t.driver_id AS assigned_driver FROM bookings b JOIN trips t ON t.id=b.trip_id WHERE b.id=? FOR UPDATE', [$bookingId]);
            if (!$booking) throw new ConflictException('Booking not found.');
            $allowed = Auth::isAdmin()
                || (Auth::isOperator() && (int) Auth::operatorId() === (int) $booking['operator_id'])
                || (Auth::isDriver() && (int) Auth::driverId() > 0 && (int) Auth::driverId() === (int) $booking['assigned_driver']);
            if (!$allowed) throw new AuthorizationException('You cannot record payments for this booking.');
            if ((bool) $booking['is_simulated']) throw new ConflictException('Example bookings cannot receive real cash payments.');
            if ($refund) {
                if ($booking['status'] !== 'cancelled' || $booking['payment_status'] !== 'paid') throw new ConflictException('Only a paid cancelled booking can be refunded.');
                $payment = $db->first("SELECT * FROM payments WHERE booking_id=? AND method='cash' AND is_mock=0 AND status='successful' FOR UPDATE", [$bookingId]);
                if (!$payment) throw new ConflictException('No collected cash payment was found.');
                $db->update('payments', ['status'=>'refunded', 'refunded_amount'=>$payment['amount']], 'id=?', [$payment['id']]);
                $db->update('bookings', ['payment_status'=>'refunded'], 'id=?', [$bookingId]);
                $amount = -(float) $payment['amount'];
                NotificationService::push((int) $booking['passenger_id'], 'payment', 'Cash refund recorded', 'The company recorded the return of your cash payment for ' . $booking['reference'] . '.', '/payments');
            } else {
                if (!in_array($booking['status'], ['confirmed','completed'], true) || $booking['payment_status'] !== 'unpaid' || (float) $booking['fare'] <= 0) throw new ConflictException('This booking is not awaiting cash collection.');
                if ($db->exists('SELECT 1 FROM payments WHERE booking_id=?', [$bookingId])) throw new ConflictException('A payment is already recorded for this booking.');
                $id = $db->insert('payments', [
                    'reference'=>ReferenceGenerator::generate('payment'), 'user_id'=>$booking['passenger_id'],
                    'booking_id'=>$bookingId, 'amount'=>$booking['fare'], 'currency'=>$booking['currency'],
                    'method'=>'cash', 'provider'=>'cash_collection', 'status'=>'successful',
                    'is_mock'=>0, 'initiated_by'=>Auth::id(), 'completed_at'=>date('Y-m-d H:i:s'),
                ]);
                $payment = $db->first('SELECT * FROM payments WHERE id=?', [$id]);
                $db->update('bookings', ['payment_status'=>'paid'], 'id=?', [$bookingId]);
                $amount = (float) $booking['fare'];
                NotificationService::paymentSuccessful((int) $booking['passenger_id'], $payment['reference'], $amount);
            }
            $db->run('UPDATE passengers SET total_spent=GREATEST(0,total_spent+?) WHERE user_id=?', [$amount,$booking['passenger_id']]);
            $db->run('UPDATE operators SET total_revenue=GREATEST(0,total_revenue+?) WHERE id=?', [$amount,$booking['operator_id']]);
            ActivityLog::record(ActivityLog::PAYMENT_RECORDED, (int) $payment['id'], 'payment', $refund ? 'Cash returned to passenger' : 'Cash received from passenger');
            return $db->first('SELECT * FROM payments WHERE id=?', [$payment['id']]);
        });
    }
}
