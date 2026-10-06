<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;

/** Calculates refundable balance from authoritative Stripe facts and durable local reservations. */
final class RefundBalance
{
    /**
     * Reconcile known refund IDs and return balance while the caller holds the attempt lock.
     *
     * @param  list<StripeRefundState>  $refunds
     */
    public function available(PaymentAttempt $attempt, StripePaymentState $payment, array $refunds, int $observedRefundedMinor): int
    {
        if ($payment->status !== 'succeeded' || $payment->capturedAmountMinor <= 0) {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Only a confirmed captured payment is refundable.');
        }
        $remote = [];
        $remoteReserved = 0;
        foreach ($refunds as $refund) {
            $this->assertMatches($attempt, $refund);
            if (isset($remote[$refund->refundId])) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Duplicate Stripe refund identity.');
            }
            $remote[$refund->refundId] = $refund;
            if (! in_array($refund->status, ['failed', 'canceled'], true)) {
                $remoteReserved += $refund->amountMinor;
            }
        }
        $localReserved = 0;
        foreach (PaymentRefund::query()->where('payment_attempt_id', $attempt->id)->get() as $local) {
            $known = $local->stripe_refund_id === null ? null : ($remote[$local->stripe_refund_id] ?? null);
            if ($known !== null) {
                $this->assertMatches($attempt, $known, $local);
                // Stripe can return a succeeded refund to requires_action before failing or canceling it.
                if (! in_array($local->status, ['failed', 'canceled'], true)) {
                    $local->update(['status' => $known->status, 'last_synced_at' => now()]);
                }
            } elseif (! in_array($local->status, ['failed', 'canceled'], true)) {
                $localReserved += $local->amount_minor;
            }
        }

        $concurrentRefundedMinor = $attempt->refunded_amount_minor > $observedRefundedMinor ? $attempt->refunded_amount_minor : 0;

        return $payment->capturedAmountMinor - max($payment->refundedAmountMinor, $remoteReserved, $concurrentRefundedMinor) - $localReserved;
    }

    /** Reject uncorrelated, malformed, or changed Stripe refund facts before releasing balance. */
    public function assertMatches(PaymentAttempt $attempt, StripeRefundState $refund, ?PaymentRefund $local = null): void
    {
        if ((! str_starts_with($refund->refundId, 're_') && ! str_starts_with($refund->refundId, 'pyr_')) || $refund->accountId !== config('nvl-payments.stripe.account_id')
            || $refund->livemode !== config('nvl-payments.stripe.livemode')
            || ($attempt->stripe_account_id !== null && $attempt->stripe_account_id !== $refund->accountId)
            || ($attempt->stripe_livemode !== null && $attempt->stripe_livemode !== $refund->livemode)
            || ($attempt->stripe_payment_intent_id !== null && $attempt->stripe_payment_intent_id !== $refund->paymentIntentId)
            || ($attempt->stripe_charge_id !== null && $attempt->stripe_charge_id !== $refund->chargeId)
            || ($attempt->stripe_payment_intent_id === null && $attempt->stripe_charge_id === null)
            || strtolower($attempt->currency) !== strtolower($refund->currency)
            || $refund->amountMinor <= 0 || $refund->amountMinor > $attempt->amount_minor
            || ! in_array($refund->status, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)
            || ($local !== null && ($refund->amountMinor !== $local->amount_minor || $refund->reason !== $local->reason
                || ($local->stripe_refund_id !== null && $local->stripe_refund_id !== $refund->refundId)))) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe refund does not match the reserved payment and refund.');
        }
    }
}
