<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\ValueObjects\OrderPaymentTimeline;

/**
 * Substitutable boundary for the supported PaymentRead workflow.
 *
 * @api
 */
interface PaymentReadContract
{
    /** Resolve and authorize the host order before reading its payment history. */
    public function forOrder(string $orderReference, Authenticatable $actor): OrderPaymentTimeline;
}
