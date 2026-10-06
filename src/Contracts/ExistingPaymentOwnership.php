<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;

/**
 * Verifies host evidence that an existing Stripe payment belongs to an order.
 *
 * @api
 */
interface ExistingPaymentOwnership
{
    /** Assert that host records or trusted metadata link the payment to the order. */
    public function assertOwned(OrderPaymentSnapshot $order, StripePaymentState $payment): void;
}
