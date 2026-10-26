<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Services\PaymentProjection;

/** Consumer-owned orchestration used by the existing behavior scenarios. */
final class StartCheckoutAction
{
    public function __construct(private readonly PaymentCheckout $service, private readonly PaymentProjection $projection) {}

    public function execute(string $orderReference, Authenticatable $actor, string $successUrl, string $cancelUrl): array
    {
        return $this->service->start($orderReference, $actor, $successUrl, $cancelUrl);
    }
}
