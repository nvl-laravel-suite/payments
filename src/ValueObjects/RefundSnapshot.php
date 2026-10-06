<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Read-only local refund and its synchronized Stripe status.
 *
 * @api
 */
final readonly class RefundSnapshot
{
    /** Hold local identity, amount, Stripe refund ID, and sync time. */
    public function __construct(
        public string $refundId,
        public string $paymentAttemptId,
        public string $operationId,
        public int $amountMinor,
        public string $currency,
        public ?string $reason,
        public string $status,
        public ?string $stripeRefundId,
        public ?CarbonImmutable $lastSyncedAt,
    ) {}
}
