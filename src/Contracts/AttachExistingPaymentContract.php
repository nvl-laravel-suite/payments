<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\ValueObjects\PaymentSnapshot;

/**
 * Substitutable boundary for the supported AttachExistingPayment workflow.
 *
 * @api
 */
interface AttachExistingPaymentContract
{
    /** Attach canonical Stripe facts once after explicit host authorization and ownership proof. */
    public function execute(string $orderReference, Authenticatable $actor, string $stripeReference, string $operationId): PaymentSnapshot;
}
