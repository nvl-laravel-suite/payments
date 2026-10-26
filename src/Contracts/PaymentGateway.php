<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Carbon\CarbonImmutable;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

/**
 * Fakeable boundary for one-time Stripe order payments.
 *
 * @api
 *
 * @phpstan-type HostedCheckout array{sessionId:string, url:string, expiresAt:\Carbon\CarbonImmutable}
 * @phpstan-type StripeCheckoutState array{sessionId:string, paymentIntentId:?string, accountId:string, livemode:bool, amountMinor:int, currency:string, status:string, paymentStatus:string, orderReference:?string, orderRevision:?string, expiresAt:\Carbon\CarbonImmutable, operationKey:?string, url:?string}
 * @phpstan-type StripePaymentState array{paymentIntentId:?string, chargeId:?string, accountId:string, livemode:bool, amountMinor:int, capturedAmountMinor:int, refundedAmountMinor:int, currency:string, status:string, captureMethod:string, amountCapturableMinor:?int, paymentMethodType:?string, partialAuthorizationStatus:?string}
 * @phpstan-type StripeRefundState array{refundId:string, paymentIntentId:?string, chargeId:?string, accountId:string, livemode:bool, amountMinor:int, currency:string, status:string, reason:?string, operationKey:?string}
 */
interface PaymentGateway
{
    /**
     * @return HostedCheckout
     */
    public function createCheckout(OrderPaymentSnapshot $order, string $captureMethod, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $idempotencyKey): array;

    public function expireCheckout(string $sessionId, string $idempotencyKey): void;

    /**
     * @return StripeCheckoutState
     */
    public function checkout(string $sessionId): array;

    /**
     * @return StripePaymentState
     */
    public function payment(string $paymentReference): array;

    /**
     * @return StripePaymentState
     */
    public function resolveExisting(string $reference): array;

    /**
     * @return StripePaymentState
     */
    public function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): array;

    /**
     * @return StripePaymentState
     */
    public function cancel(string $paymentIntentId, string $idempotencyKey): array;

    /**
     * @return StripeRefundState
     */
    public function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): array;

    /**
     * @return list<StripeRefundState>
     * @return list<StripeRefundState>
     */
    public function refunds(string $paymentId): array;
}
