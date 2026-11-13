<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;

/**
 * Calculates refundable balance from authoritative Stripe facts and durable local reservations.
 *
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripeRefundState from \Nvl\Payments\Contracts\PaymentGateway
 *
 * @internal
 */
final class RefundBalance
{
    /**
     * Reconcile known refund IDs and return balance while the caller holds the attempt lock.
     *
     * @param  list<StripeRefundState>  $refunds
     * @param  StripePaymentState  $payment
     */
    public function available(PaymentAttempt $attempt, array $payment, array $refunds, int $observedRefundedMinor): int
    {
        if ($payment['status'] !== 'succeeded' || $payment['capturedAmountMinor'] <= 0) {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Only a confirmed captured payment is refundable.');
        }
        $reversals = $payment['releasedHoldRefundIds'] ?? [];
        if (array_intersect($reversals, array_column($refunds, 'refundId')) !== []) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Stripe refund facts disagree about a partial capture reversal.');
        }
        $remote = [];
        $remoteReserved = 0;
        foreach ($refunds as $refund) {
            $this->assertMatches($attempt, $refund);
            if (isset($remote[$refund['refundId']])) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Duplicate Stripe refund identity.');
            }
            $remote[$refund['refundId']] = $refund;
            if (! in_array($refund['status'], ['failed', 'canceled'], true)) {
                $remoteReserved += $refund['amountMinor'];
            }
        }
        $localReserved = 0;
        foreach (PaymentsModelRegistry::refundQuery()->where('payment_attempt_id', $attempt->id)->orderBy('id')->lockForUpdate()->get() as $local) {
            if ($local->stripe_refund_id !== null && in_array($local->stripe_refund_id, $reversals, true)) {
                $operation = PaymentOperation::query()->lockForUpdate()->findOrFail($local->payment_operation_id);
                if ($operation->payment_attempt_id !== $attempt->id || $operation->order_reference !== $attempt->order_reference
                    || $operation->type !== 'reconcile_refund' || $operation->actor_reference !== 'system:reconciliation'
                    || $operation->status !== 'completed' || $operation->stripe_result_reference !== $local->stripe_refund_id) {
                    throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'A partial capture reversal conflicts with a local refund operation.');
                }
                if ($local->delete() !== true) {
                    throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'An imported partial capture reversal could not be removed.');
                }

                continue;
            }
            $known = $local->stripe_refund_id === null ? null : ($remote[$local->stripe_refund_id] ?? null);
            if ($known !== null) {
                $this->assertMatches($attempt, $known, $local);
                // Stripe can return a succeeded refund to requires_action before failing or canceling it.
                if (! in_array($local->status, ['failed', 'canceled'], true)) {
                    $local->update(['status' => $known['status'], 'last_synced_at' => now()]);
                }
            } elseif (! in_array($local->status, ['failed', 'canceled'], true)) {
                $localReserved += $local->amount_minor;
            }
        }

        $concurrentRefundedMinor = $attempt->refunded_amount_minor > $observedRefundedMinor ? $attempt->refunded_amount_minor : 0;

        return $payment['capturedAmountMinor'] - max($payment['refundedAmountMinor'], $remoteReserved, $concurrentRefundedMinor) - $localReserved;
    }

    /**
     * Reject uncorrelated, malformed, or changed Stripe refund facts before releasing balance.
     *
     * @param  StripeRefundState  $refund
     */
    public function assertMatches(PaymentAttempt $attempt, array $refund, ?PaymentRefund $local = null): void
    {
        if ((! str_starts_with($refund['refundId'], 're_') && ! str_starts_with($refund['refundId'], 'pyr_')) || $refund['accountId'] !== config('nvl-payments.stripe.account_id')
            || $refund['livemode'] !== config('nvl-payments.stripe.livemode')
            || ($attempt->stripe_account_id !== null && $attempt->stripe_account_id !== $refund['accountId'])
            || ($attempt->stripe_livemode !== null && $attempt->stripe_livemode !== $refund['livemode'])
            || ($attempt->stripe_payment_intent_id !== null && $attempt->stripe_payment_intent_id !== $refund['paymentIntentId'])
            || ($attempt->stripe_charge_id !== null && $attempt->stripe_charge_id !== $refund['chargeId'])
            || ($attempt->stripe_payment_intent_id === null && $attempt->stripe_charge_id === null)
            || strtolower($attempt->currency) !== strtolower($refund['currency'])
            || $refund['amountMinor'] <= 0 || $refund['amountMinor'] > $attempt->amount_minor
            || ! in_array($refund['status'], ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)
            || ($local !== null && ($refund['amountMinor'] !== $local->amount_minor || $refund['reason'] !== $local->reason
                || ($local->stripe_refund_id !== null && $local->stripe_refund_id !== $refund['refundId'])))) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe refund does not match the reserved payment and refund.');
        }
    }
}
