<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Services\PaymentProjection;
use Nvl\Payments\Services\PaymentReconciliation;

/** Consumer-owned orchestration used by the existing behavior scenarios. */
final class ReconcilePaymentAction
{
    public function __construct(private readonly PaymentReconciliation $service, private readonly PaymentProjection $projection) {}

    public function execute(string $attemptId): array
    {
        $attempt = $this->service->reconcile($attemptId);

        return $this->projection->payment($attempt, PaymentRefund::query()->where('payment_attempt_id', $attempt->id)->get());
    }
}
