<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

/** Refuses order resolution until the host supplies its trusted adapter. */
final class DenyPaymentOrderProvider implements PaymentOrderProvider
{
    /** Deny resolution without a host order provider. */
    public function resolve(string $orderReference): OrderPaymentSnapshot
    {
        throw new AuthorizationException('A host payment order provider is required.');
    }
}
