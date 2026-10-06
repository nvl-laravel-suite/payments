<?php

declare(strict_types=1);

namespace Nvl\Payments\Actions;

use Nvl\Payments\Services\PaymentReconciler;
use Nvl\Payments\ValueObjects\PaymentSnapshot;

/**
 * Repairs one internal payment using authoritative Stripe reads.
 *
 * @internal
 */
final class ReconcilePaymentAction
{
    /** Inject the reusable reconciliation write boundary. */
    public function __construct(private readonly PaymentReconciler $reconciler) {}

    /** Reconcile a trusted internal attempt identifier without issuing money movements. */
    public function execute(string $attemptId): PaymentSnapshot
    {
        return $this->reconciler->reconcile($attemptId);
    }
}
