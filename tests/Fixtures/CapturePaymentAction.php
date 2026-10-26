<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Services\PaymentAuthorizations;
use Nvl\Payments\Services\PaymentProjection;

/** Consumer-owned orchestration used by the existing behavior scenarios. */
final class CapturePaymentAction
{
    public function __construct(private readonly PaymentAuthorizations $service, private readonly PaymentProjection $projection) {}

    public function execute(string $attemptId, Authenticatable $actor, int $amountMinor, string $operationId): array
    {
        return $this->projection->payment($this->service->capture($attemptId, $amountMinor, $operationId, $actor));
    }
}
