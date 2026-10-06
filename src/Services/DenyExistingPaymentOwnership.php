<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Support\Exceptions\BindingRequiredException;

/** Refuses imports until the host proves ownership of the Stripe payment. */
final class DenyExistingPaymentOwnership implements ExistingPaymentOwnership
{
    /** Deny payment attachment without positive host ownership evidence. */
    public function assertOwned(OrderPaymentSnapshot $order, StripePaymentState $payment): void
    {
        throw BindingRequiredException::for('payments', ExistingPaymentOwnership::class, 'existing_payment_attachment');
    }
}
