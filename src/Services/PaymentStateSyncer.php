<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use DomainException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Nvl\Payments\Events\PaymentStateChanged;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;

/** Reusable transactional write boundary for authoritative Stripe financial facts. */
final class PaymentStateSyncer
{
    /** Synchronize only a previously correlated payment and notify after its connection commits. */
    public function sync(StripePaymentState $payment): void
    {
        $this->apply($payment, false);
    }

    /**
     * Accept refund reversals only with a complete validated successful-refund total.
     * Callers hold the attempt lock and reject changes since their remote observation.
     *
     * @param  list<StripeRefundState>  $refunds
     */
    public function reconcile(StripePaymentState $payment, array $refunds): void
    {
        $succeeded = 0;
        foreach ($refunds as $refund) {
            if ($refund->status === 'succeeded') {
                $succeeded += $refund->amountMinor;
            }
        }
        $this->apply($payment, $succeeded === $payment->refundedAmountMinor);
    }

    /** Apply validated facts while preserving ordinary webhook monotonicity. */
    private function apply(StripePaymentState $payment, bool $allowRefundDecrease): void
    {
        if ($payment->paymentIntentId === null && $payment->chargeId === null) {
            return;
        }
        $connection = (new PaymentAttempt)->getConnection();
        $connection->transaction(function () use ($payment, $connection, $allowRefundDecrease): void {
            $query = PaymentAttempt::query();
            if ($payment->paymentIntentId !== null) {
                $query->where('stripe_payment_intent_id', $payment->paymentIntentId);
            } else {
                $query->where('stripe_charge_id', $payment->chargeId);
            }
            $attempt = $query->lockForUpdate()->first();
            if ($attempt === null) {
                return;
            }
            $this->assertMatches($attempt, $payment);
            $state = $this->state($payment);
            if ($state === null) {
                return;
            }
            // Concurrent reads may complete in reverse order; settled money must never regress.
            if ($payment->capturedAmountMinor < $attempt->captured_amount_minor || (! $allowRefundDecrease && $payment->refundedAmountMinor < $attempt->refunded_amount_minor)
                || ($attempt->state === 'canceled' && $state !== 'canceled')
                || ($attempt->state === 'authorized' && in_array($state, ['open', 'processing', 'failed'], true))) {
                return;
            }
            $state = $attempt->state === 'payment_exception' ? 'payment_exception' : $state;
            $oldState = $attempt->state;
            $changed = $oldState !== $state || $attempt->captured_amount_minor !== $payment->capturedAmountMinor || $attempt->refunded_amount_minor !== $payment->refundedAmountMinor;
            $attempt->fill([
                'state' => $state, 'stripe_payment_intent_id' => $payment->paymentIntentId,
                'stripe_charge_id' => $payment->chargeId, 'stripe_account_id' => $payment->accountId,
                'stripe_livemode' => $payment->livemode, 'capture_method' => $payment->captureMethod,
                'captured_amount_minor' => $payment->capturedAmountMinor, 'refunded_amount_minor' => $payment->refundedAmountMinor,
                'last_synced_at' => now(),
            ]);
            if ($state === 'canceled') {
                $attempt->reservation_key = null;
            }
            $attempt->save();
            if ($changed) {
                $event = new PaymentStateChanged($attempt->order_reference, $attempt->id, $oldState, $state, $payment->paymentIntentId, $payment->chargeId, $attempt->stripe_checkout_session_id);
                $connection->afterCommit(static fn () => Event::dispatch($event));
            }
        });
    }

    /** Reject mismatched identities and impossible financial amounts before persistence. */
    public function assertMatches(PaymentAttempt $attempt, StripePaymentState $payment): void
    {
        if ($payment->accountId !== Config::get('payments.stripe.account_id') || $payment->livemode !== Config::get('payments.stripe.livemode')
            || ($attempt->stripe_account_id !== null && $attempt->stripe_account_id !== $payment->accountId)
            || ($attempt->stripe_livemode !== null && $attempt->stripe_livemode !== $payment->livemode)
            || ($attempt->stripe_payment_intent_id !== null && $attempt->stripe_payment_intent_id !== $payment->paymentIntentId)
            || ($attempt->stripe_charge_id !== null && $attempt->stripe_charge_id !== $payment->chargeId && $attempt->captured_amount_minor > 0)
            || ($attempt->capture_method !== null && $attempt->capture_method !== $payment->captureMethod)
            || $attempt->amount_minor !== $payment->amountMinor || strtolower($attempt->currency) !== strtolower($payment->currency)
            || $payment->capturedAmountMinor < 0 || $payment->capturedAmountMinor > $payment->amountMinor
            || $payment->refundedAmountMinor < 0 || $payment->refundedAmountMinor > $payment->capturedAmountMinor) {
            throw new DomainException('Stripe payment does not match the reserved attempt.');
        }
    }

    /** Map provider financial status without trusting an event's historic payload. */
    private function state(StripePaymentState $payment): ?string
    {
        return match ($payment->status) {
            'succeeded' => $payment->refundedAmountMinor > 0 ? ($payment->refundedAmountMinor === $payment->capturedAmountMinor ? 'refunded' : 'partially_refunded') : 'captured',
            'requires_capture' => 'authorized',
            'processing' => 'processing',
            'requires_payment_method', 'failed' => 'failed',
            'canceled' => 'canceled',
            'requires_action', 'requires_confirmation' => 'open',
            default => null,
        };
    }
}
