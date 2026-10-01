<?php
/**
 * UniGo - Payment gateway contract.
 *
 * The application never talks to a provider directly: it talks to this
 * interface. Swapping MockPaymentGateway for a real MTN MoMo / Airtel Money
 * implementation therefore requires no changes to controllers or models.
 *
 * SECURITY RULE: implementations must never accept or persist raw card data
 * (PAN, CVV, expiry). Only a token / provider reference may be stored.
 */

declare(strict_types=1);

namespace App\Services;

interface PaymentGatewayInterface
{
    /** Unique provider code, e.g. "mock", "mtn_momo". */
    public function name(): string;

    /** Human readable label shown on the checkout screen. */
    public function label(): string;

    /**
     * @param array{amount:float,currency:string,user_id:int,method:string,reference:string,description?:string} $request
     * @return array{status:string,provider_reference:string,message:string,raw:array<string,mixed>}
     */
    public function charge(array $request): array;

    /**
     * @param array{provider_reference:string,amount:float,currency:string} $request
     * @return array{status:string,message:string}
     */
    public function refund(array $request): array;

    /**
     * Verify a provider callback signature.
     * The mock gateway accepts everything because it generates nothing external.
     */
    public function verifyCallback(array $payload): bool;
}
