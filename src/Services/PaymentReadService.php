<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;

/**
 * Returns authorized order timelines using two configured-connection queries.
 *
 * @api
 *
 * @phpstan-import-type PaymentSnapshot from PaymentProjection
 * @phpstan-import-type RefundSnapshot from PaymentProjection
 */
final class PaymentReadService
{
    /** Inject host order admission and immutable projection mapping. */
    public function __construct(private readonly PaymentOrderProvider $orders, private readonly PaymentManagementAccess $access, private readonly PaymentProjection $projection, private readonly PaymentOrderOwnership $scope) {}

    /**
     * Resolve and authorize the host order before reading its payment history.
     *
     * @return array{orderReference:string, payments:list<PaymentSnapshot>, refunds:list<RefundSnapshot>}
     */
    public function forOrder(string $orderReference, Authenticatable $actor): array
    {
        $order = $this->orders->resolve($orderReference);
        $this->scope->assertOrder($order, $actor);
        $this->access->assertCanManage($actor, 'view', $order);
        if ($order->reference !== $orderReference) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Order provider returned a different order.');
        }
        $attempts = PaymentsModelRegistry::attemptQuery()->where('order_reference', $orderReference)->orderBy('created_at')->orderBy('id')->get();
        $refunds = PaymentsModelRegistry::refundQuery()->whereIn('payment_attempt_id', PaymentsModelRegistry::attemptQuery()->select('id')->where('order_reference', $orderReference))->orderBy('created_at')->orderBy('id')->get();
        $grouped = $refunds->groupBy('payment_attempt_id');
        $payments = [];
        foreach ($attempts as $attempt) {
            $payments[] = $this->projection->payment($attempt, $grouped->get($attempt->id, []));
        }

        $refundSnapshots = [];
        foreach ($refunds as $refund) {
            $refundSnapshots[] = $this->projection->refund($refund);
        }

        return ['orderReference' => $orderReference, 'payments' => $payments, 'refunds' => $refundSnapshots];
    }
}
