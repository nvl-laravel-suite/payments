<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use DomainException;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\ValueObjects\PaymentSnapshot;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;

/** Owns atomic reconciliation shared by automated repair and authorized Checkout recovery. */
final class PaymentReconciler
{
    /** Inject Stripe reads, validation, durable state, and projection boundaries. */
    public function __construct(private readonly PaymentGateway $gateway, private readonly PaymentStateSyncer $syncer, private readonly PaymentOperationJournal $journal, private readonly RefundBalance $balance, private readonly PaymentProjection $projection) {}

    /** Read Stripe outside transactions and atomically apply only unchanged local observations. */
    public function reconcile(string $attemptId, ?string $recoverySessionId = null): PaymentSnapshot
    {
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $connection = $attempt->getConnection();
        if (! config()->boolean('nvl-payments.enabled') || $connection->transactionLevel() !== 0) {
            throw new DomainException('Reconciliation requires enabled Payments outside a transaction.');
        }
        $observedRefunds = PaymentRefund::query()->where('payment_attempt_id', $attempt->id)->orderBy('id')->get()->map(fn (PaymentRefund $refund) => $refund->getRawOriginal())->all();
        $sessionId = $recoverySessionId ?? $attempt->stripe_checkout_session_id;
        $checkout = $sessionId === null ? null : $this->gateway->checkout($sessionId);
        if ($checkout !== null) {
            $this->assertCheckout($attempt, $checkout, $sessionId);
        }
        $reference = $checkout->paymentIntentId ?? $attempt->stripe_payment_intent_id ?? $attempt->stripe_charge_id;
        $payment = $reference === null ? null : $this->gateway->payment($reference);
        if ($payment !== null) {
            if ($reference !== $payment->paymentIntentId && $reference !== $payment->chargeId) {
                throw new DomainException('Stripe returned a different payment.');
            }
            $this->syncer->assertMatches($attempt, $payment);
        }
        $refunds = $payment === null || $payment->capturedAmountMinor === 0 ? [] : $this->gateway->refunds($reference);
        $connection->transaction(function () use ($attempt, $checkout, $payment, $refunds, $recoverySessionId, $observedRefunds): void {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($current->getRawOriginal() !== $attempt->getRawOriginal()) {
                throw new DomainException('Payment changed during reconciliation; retry with fresh Stripe facts.');
            }
            $currentRefunds = PaymentRefund::query()->where('payment_attempt_id', $attempt->id)->orderBy('id')->lockForUpdate()->get()->map(fn (PaymentRefund $refund) => $refund->getRawOriginal())->all();
            if ($currentRefunds !== $observedRefunds) {
                throw new DomainException('Refunds changed during reconciliation; retry with fresh Stripe facts.');
            }
            if ($checkout !== null) {
                $this->applyCheckout($current, $checkout, $recoverySessionId !== null);
            }
            if ($payment !== null) {
                $this->syncer->assertMatches($current, $payment);
                if ($current->stripe_payment_intent_id === null && $payment->paymentIntentId !== null) {
                    $current->update(['stripe_payment_intent_id' => $payment->paymentIntentId]);
                }
                if ($current->stripe_charge_id === null && $payment->chargeId !== null) {
                    $current->update(['stripe_charge_id' => $payment->chargeId]);
                }
                $this->applyRefunds($current, $refunds);
                $this->syncer->reconcile($payment, $refunds);
            }
            $this->resolveOperations($current, $checkout, $payment);
        }, 5);

        return $this->projection->payment($attempt->refresh(), PaymentRefund::query()->where('payment_attempt_id', $attempt->id)->get());
    }

    /** Validate all immutable Checkout facts before correlating or releasing an attempt. */
    private function assertCheckout(PaymentAttempt $attempt, StripeCheckoutState $checkout, string $sessionId): void
    {
        if ($attempt->origin !== 'checkout' || $checkout->sessionId !== $sessionId
            || ($attempt->stripe_checkout_session_id !== null && $checkout->sessionId !== $attempt->stripe_checkout_session_id)
            || $checkout->accountId !== config('nvl-payments.stripe.account_id') || $checkout->livemode !== config('nvl-payments.stripe.livemode')
            || ($attempt->stripe_account_id !== null && $checkout->accountId !== $attempt->stripe_account_id)
            || ($attempt->stripe_livemode !== null && $checkout->livemode !== $attempt->stripe_livemode)
            || $checkout->orderReference !== $attempt->order_reference || $checkout->orderRevision !== $attempt->order_revision
            || $checkout->amountMinor !== $attempt->amount_minor || strtoupper($checkout->currency) !== strtoupper($attempt->currency)
            || ($attempt->stripe_payment_intent_id !== null && $checkout->paymentIntentId !== $attempt->stripe_payment_intent_id)) {
            throw new DomainException('Checkout does not match its reserved payment.');
        }
    }

    /** Persist recovered identity only when the Session proves the original operation key. */
    private function applyCheckout(PaymentAttempt $attempt, StripeCheckoutState $checkout, bool $recovery): void
    {
        if ($recovery) {
            $operation = PaymentOperation::query()->where('payment_attempt_id', $attempt->id)->where('type', 'checkout')->lockForUpdate()->sole();
            if ($checkout->operationKey === null || ! hash_equals($operation->idempotency_key, $checkout->operationKey)) {
                throw new DomainException('Checkout recovery requires the original operation metadata.');
            }
        }
        $attempt->fill(['stripe_checkout_session_id' => $checkout->sessionId, 'stripe_account_id' => $checkout->accountId, 'stripe_livemode' => $checkout->livemode, 'expires_at' => $checkout->expiresAt, 'last_synced_at' => now()]);
        if ($checkout->url !== null) {
            $attempt->checkout_url = $checkout->url;
        }
        if (in_array($attempt->state, ['reserved', 'open', 'checking', 'expiring', 'unknown', 'expired', 'failed'], true) && $attempt->captured_amount_minor === 0) {
            if ($checkout->status === 'expired' && $checkout->paymentStatus === 'unpaid' && $checkout->paymentIntentId === null) {
                $attempt->state = 'expired';
                $attempt->reservation_key = null;
            } elseif ($checkout->status === 'complete' || $checkout->paymentStatus !== 'unpaid') {
                $attempt->state = 'processing';
            } elseif ($checkout->status === 'open') {
                $attempt->state = 'open';
            }
        }
        $attempt->save();
    }

    /**
     * Upsert verified remote refunds without matching unknown local reservations by amount.
     *
     * @param  list<StripeRefundState>  $refunds
     */
    private function applyRefunds(PaymentAttempt $attempt, array $refunds): void
    {
        $seen = [];
        foreach ($refunds as $remote) {
            if (isset($seen[$remote->refundId])) {
                throw new DomainException('Duplicate Stripe refund identity.');
            }
            $seen[$remote->refundId] = true;
            $local = PaymentRefund::query()->where('stripe_refund_id', $remote->refundId)->lockForUpdate()->first();
            if ($local !== null && $local->payment_attempt_id !== $attempt->id) {
                throw new DomainException('Refund belongs to another payment.');
            }
            if ($remote->operationKey !== null) {
                $correlated = PaymentOperation::query()->where('idempotency_key', $remote->operationKey)->lockForUpdate()->first();
                if ($correlated === null || $correlated->type !== 'refund' || $correlated->payment_attempt_id !== $attempt->id || $correlated->order_reference !== $attempt->order_reference) {
                    throw new DomainException('Refund operation metadata does not match its payment.');
                }
                $reserved = PaymentRefund::query()->where('payment_operation_id', $correlated->id)->lockForUpdate()->sole();
                if ($local !== null && $local->id !== $reserved->id) {
                    throw new DomainException('Refund identity is already assigned to another reservation.');
                }
                $local = $reserved;
            }
            $this->balance->assertMatches($attempt, $remote, $local);
            if ($local === null) {
                $operation = $this->journal->reserve('reconcile_refund', $attempt->order_reference, (string) Str::uuid(), 'system:reconciliation', $remote->refundId, $remote->amountMinor);
                $operation->update(['payment_attempt_id' => $attempt->id]);
                $local = PaymentRefund::query()->create(['payment_attempt_id' => $attempt->id, 'payment_operation_id' => $operation->id, 'stripe_refund_id' => $remote->refundId, 'amount_minor' => $remote->amountMinor, 'currency' => strtoupper($remote->currency), 'reason' => $remote->reason, 'status' => $remote->status, 'last_synced_at' => now()]);
            } else {
                if (in_array($local->status, ['failed', 'canceled'], true) && $local->status !== $remote->status) {
                    throw new DomainException('A terminal refund changed unexpectedly.');
                }
                $local->update(['stripe_refund_id' => $remote->refundId, 'status' => $remote->status, 'last_synced_at' => now()]);
                $operation = PaymentOperation::query()->findOrFail($local->payment_operation_id);
            }
            $this->journal->complete($operation, $remote->refundId);
        }
    }

    /** Close journal entries only when the exact remote object confirms a final outcome. */
    private function resolveOperations(PaymentAttempt $attempt, ?StripeCheckoutState $checkout, ?StripePaymentState $payment): void
    {
        foreach (PaymentOperation::query()->where('payment_attempt_id', $attempt->id)->whereIn('status', ['reserved', 'unknown'])->lockForUpdate()->get() as $operation) {
            $reference = match ($operation->type) {
                'checkout' => $checkout?->sessionId,
                'expire_checkout' => $checkout?->status === 'expired' && $checkout->paymentStatus === 'unpaid' ? $checkout->sessionId : null,
                'capture' => $payment?->status === 'succeeded' && $payment->capturedAmountMinor > 0 && $payment->amountCapturableMinor === 0 ? $payment->paymentIntentId : null,
                'cancel_authorization' => $payment?->status === 'canceled' && $payment->capturedAmountMinor === 0 ? $payment->paymentIntentId : null,
                default => null,
            };
            if ($reference !== null) {
                $this->journal->complete($operation, $reference);
            }
        }
    }
}
