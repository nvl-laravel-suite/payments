<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Services\PaymentProjection;
use Nvl\Payments\Services\PaymentReconciliation;

/** Consumer-owned orchestration used by the existing behavior scenarios. */
final class ResolvePaymentExceptionAction
{
    public function __construct(private readonly PaymentReconciliation $service, private readonly PaymentProjection $projection) {}

    public function execute(string $attemptId, Authenticatable $actor, string $operationId): array
    {
        return $this->projection->payment($this->service->resolveException($attemptId, $operationId, $actor));
    }
}
