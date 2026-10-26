<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Services\PaymentProjection;
use Nvl\Payments\Services\PaymentRefunds;

/** Consumer-owned orchestration used by the existing behavior scenarios. */
final class RefundPaymentAction
{
    public function __construct(private readonly PaymentRefunds $service, private readonly PaymentProjection $projection) {}

    public function execute(string $attemptId, Authenticatable $actor, int $amountMinor, string $reason, ?string $note, string $operationId): array
    {
        return $this->projection->refund($this->service->refund($attemptId, $amountMinor, $reason, $note, $operationId, $actor));
    }
}
