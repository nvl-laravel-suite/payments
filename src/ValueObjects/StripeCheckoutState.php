<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Authoritative Checkout facts, including trusted order correlation.
 *
 * @api
 */
final readonly class StripeCheckoutState
{
    public function __construct(
        public string $sessionId,
        public ?string $paymentIntentId,
        public string $accountId,
        public bool $livemode,
        public int $amountMinor,
        public string $currency,
        public string $status,
        public string $paymentStatus,
        public ?string $orderReference,
        public ?string $orderRevision,
        public CarbonImmutable $expiresAt,
        public ?string $operationKey = null,
        public ?string $url = null,
    ) {}
}
