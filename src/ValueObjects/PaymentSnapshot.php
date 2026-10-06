<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Read-only local payment attempt and its synchronized Stripe facts.
 *
 * @api
 */
final readonly class PaymentSnapshot
{
    /** Hold local identity, financial state, Stripe references, and sync time. */
    public function __construct(
        public string $attemptId,
        public string $orderReference,
        public string $orderRevision,
        public int $amountMinor,
        public int $capturedAmountMinor,
        public int $refundedAmountMinor,
        public string $currency,
        public string $status,
        public string $origin,
        public ?string $checkoutSessionId,
        public ?string $paymentIntentId,
        public ?string $chargeId,
        public ?string $accountId,
        public ?bool $livemode,
        public ?string $captureMethod,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $lastSyncedAt,
        public int $reservedRefundAmountMinor = 0,
        public int $refundableAmountMinor = 0,
    ) {}
}
