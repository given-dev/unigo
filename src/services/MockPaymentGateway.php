<?php
/**
 * UniGo - Mock payment gateway (demo only).
 *
 * This simulates a mobile-money / card authorisation so the whole payment
 * flow - pending -> successful/failed -> refunded - can be demonstrated and
 * tested without a real merchant account.
 *
 * Explicitly labelled as a mock everywhere in the UI. NO real money moves.
 * It deliberately refuses any payload that looks like raw card data.
 */

declare(strict_types=1);

namespace App\Services;

final class MockPaymentGateway implements PaymentGatewayInterface
{
    /** Deterministic failure triggers used by the demo seeder / docs. */
    public const FORCE_FAIL = 'FAIL';

    public function name(): string
    {
        return 'mock';
    }

    public function label(): string
    {
        return 'Demo gateway (no real money)';
    }

    public function charge(array $request): array
    {
        $amount = (float) ($request['amount'] ?? 0);
        $method = (string) ($request['method'] ?? 'mobile_money');
        $phone  = (string) ($request['account'] ?? '');

        if ($amount <= 0) {
            return $this->fail('The amount must be greater than zero.', 'invalid_amount');
        }

        // Guard rail: this gateway must never be handed raw card data.
        if ($this->looksLikeRawCardData($request)) {
            return $this->fail('Card details must be tokenised by the provider SDK.', 'raw_card_data_rejected');
        }

        // A phone number of 700000000 is treated as "decline" in the demo so the
        // failure path can be shown without touching a provider sandbox.
        if ($method === 'mobile_money' && $phone !== '' && $this->isForcedFailure($phone)) {
            return $this->fail('The mobile money account was declined by the provider.', 'declined');
        }

        if ($method === 'card' && $this->isForcedFailure((string) ($request['token'] ?? $phone))) {
            return $this->fail('The card was declined.', 'declined');
        }

        return [
            'status'             => 'successful',
            'provider_reference' => 'MOCK-' . strtoupper(bin2hex(random_bytes(6))),
            'message'            => $this->successMessage($method),
            'raw'                => [
                'gateway'  => 'mock',
                'amount'   => $amount,
                'currency' => $request['currency'] ?? 'UGX',
                'method'   => $method,
                'settled_at' => date('c'),
                'simulated' => true,
            ],
        ];
    }

    public function refund(array $request): array
    {
        return [
            'status'  => 'refunded',
            'message' => 'The amount has been returned to the original payment method (simulated).',
        ];
    }

    public function verifyCallback(array $payload): bool
    {
        return true;
    }

    // ------------------------------------------------------------------

    private function fail(string $message, string $code): array
    {
        return [
            'status'             => 'failed',
            'provider_reference' => 'MOCK-' . strtoupper(bin2hex(random_bytes(6))),
            'message'            => $message,
            'raw'                => ['gateway' => 'mock', 'code' => $code, 'simulated' => true],
        ];
    }

    private function successMessage(string $method): string
    {
        return match ($method) {
            'mobile_money' => 'Mobile money payment successful (simulated).',
            'card'         => 'Card payment successful (simulated).',
            'wallet'       => 'Wallet payment successful (simulated).',
            default        => 'Payment successful (simulated).',
        };
    }

    private function isForcedFailure(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        return str_contains($digits, '700000000');
    }

    /**
     * Reject payloads that contain something card-like. Keys inspected:
     * card_number, pan, cvv, cvc, expiry, card_no.
     */
    private function looksLikeRawCardData(array $request): bool
    {
        foreach (['card_number', 'card_no', 'pan', 'cvv', 'cvc', 'card_expiry', 'expiry'] as $key) {
            if (array_key_exists($key, $request)) {
                return true;
            }
        }
        return false;
    }
}
