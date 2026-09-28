<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

/** Supplies a host-owned order's trusted payment facts. */
interface PaymentOrderProvider
{
    /** Resolve a host order reference to its current server-calculated payment snapshot. */
    public function resolve(string $orderReference): OrderPaymentSnapshot;
}
