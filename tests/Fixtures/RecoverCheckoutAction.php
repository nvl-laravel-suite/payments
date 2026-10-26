<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Services\PaymentProjection;

/** Consumer-owned orchestration used by the existing behavior scenarios. */
final class RecoverCheckoutAction
{
    public function __construct(private readonly PaymentCheckout $service, private readonly PaymentProjection $projection) {}

    public function execute(string $attemptId, string $stripeSessionId, Authenticatable $actor): array
    {
        return $this->projection->payment($this->service->recover($attemptId, $stripeSessionId, $actor));
    }
}
