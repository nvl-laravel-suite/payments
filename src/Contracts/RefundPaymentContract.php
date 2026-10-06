<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\ValueObjects\RefundSnapshot;

/**
 * Substitutable boundary for the supported RefundPayment workflow.
 *
 * @api
 */
interface RefundPaymentContract
{
    /** Reserve refundable funds atomically and issue one idempotent refund to the original payment method. */
    public function execute(string $attemptId, Authenticatable $actor, int $amountMinor, string $reason, ?string $note, string $operationId): RefundSnapshot;
}
