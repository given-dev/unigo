<?php
/**
 * UniGo - Payment service (facade).
 *
 * Owns gateway selection and the payment ledger workflow:
 *   pending -> successful | failed -> refunded
 * Every transaction is stored in the `payments` table with is_mock = 1 while
 * the mock gateway is active, so demo transactions are always identifiable.
 *
 * SECURITY: raw card data is never accepted, logged or stored.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Config;
use App\Core\Database;
use App\Models\PaymentModel;

final class PaymentService
{
    private static ?PaymentGatewayInterface $gateway = null;

    public static function gateway(): PaymentGatewayInterface
    {
        if (self::$gateway === null) {
            if (!Config::get('domain.demo_mode', false)) {
                throw new \App\Core\ValidationException('Online payments are not connected. Choose cash payment.');
            }
            // To go live, swap this for a real adapter, e.g.
            //   self::$gateway = new MtnMobileMoneyGateway(MtnConfig::fromConfig());
            // No controller or model changes would be required.
            self::$gateway = new MockPaymentGateway();
        }
        return self::$gateway;
    }

    public static function useGateway(PaymentGatewayInterface $gateway): void
    {
        self::$gateway = $gateway;
    }

    /** Methods offered on the checkout screen. */
    public static function methods(): array
    {
        if (!Config::get('domain.demo_mode', false)) {
            return [['value' => 'cash', 'label' => 'Cash', 'hint' => 'Pay the company cashier or driver; a receipt is issued after collection.', 'icon' => 'cash']];
        }
        return [
            ['value' => 'mobile_money', 'label' => 'Mobile Money', 'hint' => 'MTN MoMo / Airtel Money (simulated)', 'icon' => 'phone'],
            ['value' => 'card',         'label' => 'Card',         'hint' => 'Tokenised card (simulated)', 'icon' => 'card'],
            ['value' => 'wallet',       'label' => 'UniGo Wallet', 'hint' => 'Pay from your UniGo balance', 'icon' => 'wallet'],
            ['value' => 'cash',         'label' => 'Cash',         'hint' => 'Pay the driver or cashier on board', 'icon' => 'cash'],
        ];
    }

    public static function isMock(): bool
    {
        return Config::get('domain.demo_mode', false) && self::gateway() instanceof MockPaymentGateway;
    }

    /**
     * Record a payment attempt and apply the result.
     *
     * @param array{booking_id?:int,delivery_id?:int,account?:string,token?:string,description?:string} $context
     * @return array<string,mixed>
     */
    public static function charge(int $userId, float $amount, string $method, array $context = [], ?int $actorId = null): array
    {
        if (!Config::get('domain.demo_mode', false)) {
            throw new \App\Core\ValidationException('Online payments are not connected. Choose cash payment.');
        }
        $db = Database::instance();
        $model = new PaymentModel();
        $reference = ReferenceGenerator::generate('payment');
        $currency = (string) Config::get('app.currency', 'UGX');

        $paymentId = $model->create([
            'reference'      => $reference,
            'user_id'        => $userId,
            'booking_id'     => $context['booking_id'] ?? null,
            'delivery_id'    => $context['delivery_id'] ?? null,
            'amount'         => $amount,
            'currency'       => $currency,
            'method'         => $method,
            'provider'       => self::gateway()->name(),
            'masked_account' => self::mask((string) ($context['account'] ?? '')),
            'status'         => 'pending',
            'is_mock'        => self::isMock() ? 1 : 0,
            'initiated_by'   => $actorId,
        ]);

        $result = self::gateway()->charge([
            'amount'      => $amount,
            'currency'    => $currency,
            'user_id'     => $userId,
            'method'      => $method,
            'reference'   => $reference,
            'account'     => (string) ($context['account'] ?? ''),
            'token'       => (string) ($context['token'] ?? ''),
            'description' => (string) ($context['description'] ?? ''),
        ]);

        $status = match ($result['status']) {
            'successful' => 'successful',
            'refunded'   => 'refunded',
            default      => 'failed',
        };

        $model->updateById($paymentId, [
            'status'             => $status,
            'provider_reference' => (string) $result['provider_reference'],
            'failure_reason'     => $status === 'failed' ? mb_substr((string) $result['message'], 0, 160) : '',
            'completed_at'       => date('Y-m-d H:i:s'),
        ]);

        if ($status === 'successful') {
            $db->run(
                'UPDATE passengers SET total_spent = total_spent + ?
                 WHERE user_id = ?',
                [$amount, $userId]
            );
            if (!empty($context['booking_id'])) {
                $db->run(
                    'UPDATE operators o
                     JOIN trips t ON t.operator_id = o.id
                     JOIN bookings b ON b.trip_id = t.id
                     SET o.total_revenue = o.total_revenue + ?
                     WHERE b.id = ?',
                    [$amount, (int) $context['booking_id']]
                );
            }
            NotificationService::paymentSuccessful($userId, $reference, $amount);
        }

        ActivityLog::record(
            ActivityLog::PAYMENT_RECORDED,
            $paymentId,
            'payment',
            $reference . ' ' . $status . ' ' . $amount . ' ' . $currency . ' via ' . $method
        );

        $payment = $model->find($paymentId) ?? [];
        $payment['message'] = (string) $result['message'];
        return $payment;
    }

    /** Refund every successful payment attached to a booking. */
    public static function refundForBooking(int $bookingId, ?int $actorId = null): int
    {
        $db = Database::instance();
        $model = new PaymentModel();
        $payments = $db->select(
            "SELECT * FROM payments WHERE booking_id = ? AND status = 'successful'",
            [$bookingId]
        );

        $count = 0;
        foreach ($payments as $payment) {
            if ($payment['method'] === 'cash') continue; // Cash is returned and recorded by authorized staff.
            if (!Config::get('domain.demo_mode', false)) {
                throw new \App\Core\ConflictException('This payment requires a connected provider to process a refund.');
            }
            $result = self::gateway()->refund([
                'provider_reference' => (string) $payment['provider_reference'],
                'amount'             => (float) $payment['amount'],
                'currency'           => (string) $payment['currency'],
            ]);
            if (($result['status'] ?? '') !== 'refunded') throw new \App\Core\ConflictException('The refund has not been confirmed.');
            $model->updateById((int) $payment['id'], [
                'status'          => 'refunded',
                'refunded_amount' => (float) $payment['amount'],
            ]);
            NotificationService::push(
                (int) $payment['user_id'],
                'payment',
                'Refund processed',
                'Your payment of ' . number_format((float) $payment['amount'], 0) . ' ' . $payment['currency'] . ' has been refunded (simulated).',
                '/payments',
                'info',
                'wallet'
            );
            $db->run('UPDATE passengers SET total_spent = GREATEST(0, total_spent - ?) WHERE user_id = ?', [(float) $payment['amount'], (int) $payment['user_id']]);
            $db->run('UPDATE operators o JOIN trips t ON t.operator_id = o.id JOIN bookings b ON b.trip_id = t.id SET o.total_revenue = GREATEST(0, o.total_revenue - ?) WHERE b.id = ?', [(float) $payment['amount'], $bookingId]);
            $count++;
        }

        if ($count > 0) {
            $db->update('bookings', ['payment_status' => 'refunded'], 'id = ?', [$bookingId]);
        }
        return $count;
    }

    /** Wallet top-up (simulated). */
    public static function topUpWallet(int $userId, float $amount, string $method = 'mobile_money'): array
    {
        return self::charge($userId, $amount, $method, ['description' => 'Wallet top-up']);
    }

    private static function mask(string $account): string
    {
        $account = trim($account);
        if ($account === '') {
            return '';
        }
        if (strlen($account) <= 4) {
            return str_repeat('*', strlen($account));
        }
        return str_repeat('*', max(0, strlen($account) - 4)) . substr($account, -4);
    }
}
