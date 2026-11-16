<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

/**
 * Optional gateway capability for refund-object webhooks.
 *
 * @api
 *
 * @phpstan-import-type StripeRefundState from PaymentGateway
 */
interface PaymentRefundLookup
{
    /** @return StripeRefundState */
    public function readRefund(string $refundId): array;
}
