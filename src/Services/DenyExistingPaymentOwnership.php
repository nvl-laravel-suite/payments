<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Exceptions\BindingRequiredException;

/**
 * Refuses imports until the host proves ownership of the Stripe payment.
 *
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 *
 * @internal
 */
final class DenyExistingPaymentOwnership implements ExistingPaymentOwnership
{
    /**
     * Deny payment attachment without positive host ownership evidence.
     *
     * @param  StripePaymentState  $payment
     */
    public function assertOwned(OrderPaymentSnapshot $order, array $payment): void
    {
        throw BindingRequiredException::for('payments', ExistingPaymentOwnership::class, 'existing_payment_attachment');
    }
}
