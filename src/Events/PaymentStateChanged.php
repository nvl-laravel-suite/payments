<?php

declare(strict_types=1);

namespace Nvl\Payments\Events;

use Nvl\Support\Contracts\DomainEvent;

/** Committed financial facts for a host-owned order and its payment attempt. *
 * @api
 */
final readonly class PaymentStateChanged implements DomainEvent
{
    /** Carry the transition and canonical Stripe references without customer data. */
    public function __construct(
        public string $orderReference,
        public string $attemptId,
        public string $oldState,
        public string $newState,
        public ?string $paymentIntentId,
        public ?string $chargeId,
        public ?string $checkoutSessionId,
        public int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
