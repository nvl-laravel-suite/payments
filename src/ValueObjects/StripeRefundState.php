<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

/** Authoritative refund facts; pending is distinct from succeeded. */
final readonly class StripeRefundState
{
    public function __construct(
        public string $refundId,
        public ?string $paymentIntentId,
        public ?string $chargeId,
        public string $accountId,
        public bool $livemode,
        public int $amountMinor,
        public string $currency,
        public string $status,
        public ?string $reason,
        public ?string $operationKey = null,
    ) {}
}
