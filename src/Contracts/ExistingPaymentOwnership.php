<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

/**
 * Verifies host evidence that an existing Stripe payment belongs to an order.
 *
 * @api
 *
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 */
interface ExistingPaymentOwnership
{
    /**
     * Assert that host records or trusted metadata link the payment to the order.
     *
     * @param  StripePaymentState  $payment
     */
    public function assertOwned(OrderPaymentSnapshot $order, array $payment): void;
}
