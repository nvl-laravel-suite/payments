<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Carbon\CarbonImmutable;
use Nvl\Payments\ValueObjects\HostedCheckout;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;

/** Fakeable boundary for one-time Stripe order payments. */
interface PaymentGateway
{
    public function createCheckout(OrderPaymentSnapshot $order, string $captureMethod, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $idempotencyKey): HostedCheckout;

    public function expireCheckout(string $sessionId, string $idempotencyKey): void;

    public function checkout(string $sessionId): StripeCheckoutState;

    public function payment(string $paymentReference): StripePaymentState;

    public function resolveExisting(string $reference): StripePaymentState;

    public function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): StripePaymentState;

    public function cancel(string $paymentIntentId, string $idempotencyKey): StripePaymentState;

    public function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): StripeRefundState;

    /** @return list<StripeRefundState> */
    public function refunds(string $paymentId): array;
}
