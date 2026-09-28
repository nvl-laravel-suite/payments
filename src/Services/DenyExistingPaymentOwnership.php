<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;

/** Refuses imports until the host proves ownership of the Stripe payment. */
final class DenyExistingPaymentOwnership implements ExistingPaymentOwnership
{
    /** Deny payment attachment without positive host ownership evidence. */
    public function assertOwned(OrderPaymentSnapshot $order, StripePaymentState $payment): void
    {
        throw new AuthorizationException('A host payment ownership binding is required.');
    }
}
