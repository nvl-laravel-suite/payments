<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Carbon\CarbonImmutable;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\ValueObjects\PaymentSnapshot;
use Nvl\Payments\ValueObjects\RefundSnapshot;

/** Shapes immutable payment facts with conservative local refund reservations. */
final class PaymentProjection
{
    /**
     * Project supplied rows without triggering additional database reads.
     *
     * @param  iterable<PaymentRefund>  $refunds
     */
    public function payment(PaymentAttempt $attempt, iterable $refunds = []): PaymentSnapshot
    {
        $succeeded = 0;
        $reserved = 0;
        foreach ($refunds as $refund) {
            if ($refund->status === 'succeeded') {
                $succeeded += $refund->amount_minor;
            } elseif (! in_array($refund->status, ['failed', 'canceled'], true)) {
                $reserved += $refund->amount_minor;
            }
        }
        $refunded = max($attempt->refunded_amount_minor, $succeeded);

        return new PaymentSnapshot($attempt->id, $attempt->order_reference, $attempt->order_revision, $attempt->amount_minor,
            $attempt->captured_amount_minor, $refunded, $attempt->currency, $attempt->state, $attempt->origin,
            $attempt->stripe_checkout_session_id, $attempt->stripe_payment_intent_id, $attempt->stripe_charge_id, $attempt->stripe_account_id,
            $attempt->stripe_livemode, $attempt->capture_method,
            $attempt->expires_at === null ? null : CarbonImmutable::instance($attempt->expires_at),
            $attempt->last_synced_at === null ? null : CarbonImmutable::instance($attempt->last_synced_at), $reserved,
            max(0, $attempt->captured_amount_minor - $refunded - $reserved));
    }

    /** Project a refund without exposing internal notes or operation credentials. */
    public function refund(PaymentRefund $refund): RefundSnapshot
    {
        return new RefundSnapshot($refund->id, $refund->payment_attempt_id, $refund->payment_operation_id, $refund->amount_minor,
            $refund->currency, $refund->reason, $refund->status, $refund->stripe_refund_id,
            $refund->last_synced_at === null ? null : CarbonImmutable::instance($refund->last_synced_at));
    }
}
