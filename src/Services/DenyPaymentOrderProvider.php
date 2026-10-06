<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Exceptions\BindingRequiredException;

/** Refuses order resolution until the host supplies its trusted adapter. */
final class DenyPaymentOrderProvider implements PaymentOrderProvider
{
    /** Deny resolution without a host order provider. */
    public function resolve(string $orderReference): OrderPaymentSnapshot
    {
        throw BindingRequiredException::for('payments', PaymentOrderProvider::class, 'order_resolution');
    }
}
