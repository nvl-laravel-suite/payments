<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Services\PaymentAuthorizations;
use Nvl\Payments\Services\PaymentProjection;

/** Consumer-owned orchestration used by the existing behavior scenarios. */
final class CancelAuthorizationAction
{
    public function __construct(private readonly PaymentAuthorizations $service, private readonly PaymentProjection $projection) {}

    public function execute(string $attemptId, Authenticatable $actor, string $operationId): array
    {
        return $this->projection->payment($this->service->cancel($attemptId, $operationId, $actor));
    }
}
