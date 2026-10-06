<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A Stripe Checkout Session URL reserved for a specific order payment.
 *
 * @api
 */
final readonly class HostedCheckout
{
    /** Hold the Session reference, customer URL, and expiry. */
    public function __construct(
        public string $sessionId,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
