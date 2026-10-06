<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\ValueObjects\PaymentSnapshot;

/**
 * Substitutable boundary for the supported RecoverCheckout workflow.
 *
 * @api
 */
interface RecoverCheckoutContract
{
    /** Recover a supplied Stripe Session only after host authorization and remote proof. */
    public function execute(string $attemptId, string $stripeSessionId, Authenticatable $actor): PaymentSnapshot;
}
