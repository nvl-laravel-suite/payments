<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\ValueObjects\OrderPaymentTimeline;

/**
 * Returns authorized order timelines using two configured-connection queries.
 *
 * @api
 */
final class PaymentReadService
{
    /** Inject host order admission and immutable projection mapping. */
    public function __construct(private readonly PaymentOrderProvider $orders, private readonly PaymentManagementAccess $access, private readonly PaymentProjection $projection) {}

    /** Resolve and authorize the host order before reading its payment history. */
    public function forOrder(string $orderReference, Authenticatable $actor): OrderPaymentTimeline
    {
        $order = $this->orders->resolve($orderReference);
        $this->access->assertCanManage($actor, 'view', $order);
        if ($order->reference !== $orderReference) {
            throw new DomainException('Order provider returned a different order.');
        }
        $attempts = PaymentAttempt::query()->where('order_reference', $orderReference)->orderBy('created_at')->orderBy('id')->get();
        $refunds = PaymentRefund::query()->whereIn('payment_attempt_id', PaymentAttempt::query()->select('id')->where('order_reference', $orderReference))->orderBy('created_at')->orderBy('id')->get();
        $grouped = $refunds->groupBy('payment_attempt_id');
        $payments = [];
        foreach ($attempts as $attempt) {
            $payments[] = $this->projection->payment($attempt, $grouped->get($attempt->id, []));
        }

        $refundSnapshots = [];
        foreach ($refunds as $refund) {
            $refundSnapshots[] = $this->projection->refund($refund);
        }

        return new OrderPaymentTimeline($orderReference, $payments, $refundSnapshots);
    }
}
