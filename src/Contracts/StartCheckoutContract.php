<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\ValueObjects\HostedCheckout;

/**
 * Substitutable boundary for the supported StartCheckout workflow.
 *
 * @api
 */
interface StartCheckoutContract
{
    /** Return an existing safe URL or reserve and create a new Checkout Session. */
    public function execute(string $orderReference, Authenticatable $actor, string $successUrl, string $cancelUrl): HostedCheckout;
}
