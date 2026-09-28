<?php

declare(strict_types=1);

namespace Nvl\Payments\Events;

/** Committed financial facts for a host-owned order and its payment attempt. */
final readonly class PaymentStateChanged
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
    ) {}
}
