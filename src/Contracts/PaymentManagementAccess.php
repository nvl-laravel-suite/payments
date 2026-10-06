<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

/**
 * Delegates operation-specific order payment authorization to the host.
 *
 * @api
 */
interface PaymentManagementAccess
{
    /** Assert that the actor may perform the operation on the order. */
    public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void;
}
