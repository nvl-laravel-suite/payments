<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

/**
 * Read-only payment attempts and refunds associated with a host order.
 *
 * @api
 */
final readonly class OrderPaymentTimeline
{
    /**
     * Hold ordered payment and refund snapshots for one order.
     *
     * @param  list<PaymentSnapshot>  $payments
     * @param  list<RefundSnapshot>  $refunds
     */
    public function __construct(
        public string $orderReference,
        public array $payments,
        public array $refunds,
    ) {}
}
