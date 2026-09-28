<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

/** Refuses every management operation until the host supplies authorization. */
final class DenyPaymentManagementAccess implements PaymentManagementAccess
{
    /** Deny the requested payment operation without host authorization. */
    public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void
    {
        throw new AuthorizationException('A host payment management access binding is required.');
    }
}
