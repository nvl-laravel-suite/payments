<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Exceptions\BindingRequiredException;

/** Refuses every management operation until the host supplies authorization. */
final class DenyPaymentManagementAccess implements PaymentManagementAccess
{
    /** Deny the requested payment operation without host authorization. */
    public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void
    {
        throw BindingRequiredException::for('payments', PaymentManagementAccess::class, 'payment_management');
    }
}
