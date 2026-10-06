<?php

declare(strict_types=1);

namespace Nvl\Payments\Actions;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Contracts\RecoverCheckoutContract;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Services\PaymentReconciler;
use Nvl\Payments\ValueObjects\PaymentSnapshot;

/**
 * Allows host-authorized recovery of a lost Checkout response with exact operation proof.
 *
 * @api
 */
final class RecoverCheckoutAction implements RecoverCheckoutContract
{
    /** Inject host admission and the shared reconciliation boundary. */
    public function __construct(private readonly PaymentOrderProvider $orders, private readonly PaymentManagementAccess $access, private readonly PaymentReconciler $reconciler) {}

    /** Recover a supplied Stripe Session only after host authorization and remote proof. */
    public function execute(string $attemptId, string $stripeSessionId, Authenticatable $actor): PaymentSnapshot
    {
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $order = $this->orders->resolve($attempt->order_reference);
        $this->access->assertCanManage($actor, 'recover_checkout', $order);
        if ($order->reference !== $attempt->order_reference || ! str_starts_with($stripeSessionId, 'cs_')) {
            throw new DomainException('Invalid Checkout recovery identity.');
        }

        return $this->reconciler->reconcile($attemptId, $stripeSessionId);
    }
}
