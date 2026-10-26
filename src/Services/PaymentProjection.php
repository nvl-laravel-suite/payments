<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Carbon\CarbonImmutable;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;

/**
 * Shapes immutable payment facts with conservative local refund reservations.
 *
 * @phpstan-type PaymentSnapshot array{attemptId:string, orderReference:string, orderRevision:string, amountMinor:int, capturedAmountMinor:int, refundedAmountMinor:int, currency:string, status:string, origin:string, checkoutSessionId:?string, paymentIntentId:?string, chargeId:?string, accountId:?string, livemode:?bool, captureMethod:?string, expiresAt:?\Carbon\CarbonImmutable, lastSyncedAt:?\Carbon\CarbonImmutable, reservedRefundAmountMinor:int, refundableAmountMinor:int}
 * @phpstan-type RefundSnapshot array{refundId:string, paymentAttemptId:string, operationId:string, amountMinor:int, currency:string, reason:?string, status:string, stripeRefundId:?string, lastSyncedAt:?\Carbon\CarbonImmutable}
 *
 * @internal
 */
final class PaymentProjection
{
    /**
     * Project supplied rows without triggering additional database reads.
     *
     * @param  iterable<PaymentRefund>  $refunds
     * @return PaymentSnapshot
     */
    public function payment(PaymentAttempt $attempt, iterable $refunds = []): array
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

        return ['attemptId' => $attempt->id, 'orderReference' => $attempt->order_reference, 'orderRevision' => $attempt->order_revision, 'amountMinor' => $attempt->amount_minor, 'capturedAmountMinor' => $attempt->captured_amount_minor, 'refundedAmountMinor' => $refunded, 'currency' => $attempt->currency, 'status' => $attempt->state, 'origin' => $attempt->origin, 'checkoutSessionId' => $attempt->stripe_checkout_session_id, 'paymentIntentId' => $attempt->stripe_payment_intent_id, 'chargeId' => $attempt->stripe_charge_id, 'accountId' => $attempt->stripe_account_id, 'livemode' => $attempt->stripe_livemode, 'captureMethod' => $attempt->capture_method, 'expiresAt' => $attempt->expires_at === null ? null : CarbonImmutable::instance($attempt->expires_at), 'lastSyncedAt' => $attempt->last_synced_at === null ? null : CarbonImmutable::instance($attempt->last_synced_at), 'reservedRefundAmountMinor' => $reserved, 'refundableAmountMinor' => max(0, $attempt->captured_amount_minor - $refunded - $reserved)];
    }

    /**
     * Project a refund without exposing internal notes or operation credentials.
     *
     * @return RefundSnapshot
     */
    public function refund(PaymentRefund $refund): array
    {
        return ['refundId' => $refund->id, 'paymentAttemptId' => $refund->payment_attempt_id, 'operationId' => $refund->payment_operation_id, 'amountMinor' => $refund->amount_minor, 'currency' => $refund->currency, 'reason' => $refund->reason, 'status' => $refund->status, 'stripeRefundId' => $refund->stripe_refund_id, 'lastSyncedAt' => $refund->last_synced_at === null ? null : CarbonImmutable::instance($refund->last_synced_at)];
    }
}
