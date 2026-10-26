<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Events\PaymentStateChanged;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Ownership\PaymentsIntegrityGuard;
use Nvl\Support\Events\DomainEventDispatcher;

/**
 * Owns atomic reconciliation shared by automated repair and authorized Checkout recovery.
 *
 * @api
 *
 * @phpstan-import-type StripeCheckoutState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripeRefundState from \Nvl\Payments\Contracts\PaymentGateway
 */
final class PaymentReconciliation
{
    /** Inject Stripe reads, validation, durable state, and projection boundaries. */
    public function __construct(private readonly PaymentGateway $gateway, private readonly PaymentStateSyncer $syncer, private readonly PaymentOperationJournal $journal, private readonly RefundBalance $balance, private readonly PaymentsIntegrityGuard $integrity, private readonly PaymentRefundWriter $refundWriter, private readonly PaymentOrderProvider $orders, private readonly PaymentManagementAccess $access, private readonly PaymentOrderOwnership $scope, private readonly ?DomainEventDispatcher $domainEvents = null) {}

    /** Accept a confirmed payment exception once with an immutable actor and operation UUID. */
    public function resolveException(PaymentAttempt|string $attempt, string $operationId, Authenticatable $actor): PaymentAttempt
    {
        $attemptId = $attempt instanceof PaymentAttempt ? $attempt->id : $attempt;
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $order = $this->orders->resolve($attempt->order_reference);
        $this->scope->assertOrder($order, $actor);
        $this->access->assertCanManage($actor, 'resolve_payment_exception', $order);
        $connection = $attempt->getConnection();
        if (! config()->boolean('nvl-payments.enabled')) {
            throw PaymentsException::because(PaymentsResponseCode::FeatureDisabled, 'Payments is disabled.');
        }
        if ($order->reference !== $attempt->order_reference) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Order provider returned a different order.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new DomainException('Exception resolution requires enabled Payments outside a transaction.');
        }
        $actorId = $actor->getAuthIdentifier();
        if ((! is_string($actorId) && ! is_int($actorId)) || trim((string) $actorId) === '') {
            throw new InvalidArgumentException('A stable actor identifier is required.');
        }
        $existing = PaymentOperation::query()->find($operationId);
        if ($existing !== null) {
            $operation = $this->journal->reserve('resolve_payment_exception', $attempt->order_reference, $operationId, (string) $actorId, $attemptId, null);
            if ($operation->status !== 'completed') {
                throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Exception acceptance is unresolved.');
            }
        } else {
            $reference = $attempt->stripe_payment_intent_id ?? $attempt->stripe_charge_id ?? throw new DomainException('Missing Stripe payment identity.');
            $payment = $this->gateway->payment($reference);
            $this->syncer->assertMatches($attempt, $payment);
            if ($payment['status'] !== 'succeeded' || $payment['capturedAmountMinor'] <= 0) {
                throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Only confirmed captured money can be accepted.');
            }
            $connection->transaction(function () use ($attempt, $payment, $actorId, $operationId, $connection): void {
                $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($current->getRawOriginal() !== $attempt->getRawOriginal() || $current->state !== 'payment_exception') {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Payment exception changed; review its current facts before accepting.');
                }
                $operation = $this->journal->reserve('resolve_payment_exception', $current->order_reference, $operationId, (string) $actorId, $current->id, null);
                $operation->update(['payment_attempt_id' => $current->id]);
                $this->syncer->sync($payment);
                $current->refresh();
                $state = $current->refunded_amount_minor > 0 ? ($current->refunded_amount_minor === $current->captured_amount_minor ? 'refunded' : 'partially_refunded') : 'captured';
                $current->update(['state' => $state]);
                $this->journal->complete($operation, $current->stripe_payment_intent_id ?? $current->stripe_charge_id ?? $current->id);
                $event = new PaymentStateChanged($current->order_reference, $current->id, 'payment_exception', $state, $current->stripe_payment_intent_id, $current->stripe_charge_id, $current->stripe_checkout_session_id);
                ($this->domainEvents ?? DomainEventDispatcher::current())->dispatch($event, $connection);
            }, 5);
        }

        return $attempt->refresh();
    }

    /** Read Stripe outside transactions and atomically apply only unchanged local observations. */
    public function reconcile(PaymentAttempt|string $attempt, ?string $recoverySessionId = null): PaymentAttempt
    {
        $this->integrity->assertReady();
        $attemptId = $attempt instanceof PaymentAttempt ? $attempt->id : $attempt;
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $connection = $attempt->getConnection();
        if (! config()->boolean('nvl-payments.enabled')) {
            throw PaymentsException::because(PaymentsResponseCode::FeatureDisabled, 'Payments is disabled.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new DomainException('Reconciliation requires enabled Payments outside a transaction.');
        }
        $observedRefunds = PaymentRefund::query()->where('payment_attempt_id', $attempt->id)->orderBy('id')->get()->map(fn (PaymentRefund $refund) => $refund->getRawOriginal())->all();
        $sessionId = $recoverySessionId ?? $attempt->stripe_checkout_session_id;
        $checkout = $sessionId === null ? null : $this->gateway->checkout($sessionId);
        if ($checkout !== null) {
            $this->assertCheckout($attempt, $checkout, $sessionId);
        }
        $reference = $checkout['paymentIntentId'] ?? $attempt->stripe_payment_intent_id ?? $attempt->stripe_charge_id;
        $payment = $reference === null ? null : $this->gateway->payment($reference);
        if ($payment !== null) {
            if ($reference !== $payment['paymentIntentId'] && $reference !== $payment['chargeId']) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe returned a different payment.');
            }
            $this->syncer->assertMatches($attempt, $payment);
        }
        $refunds = $payment === null || $payment['capturedAmountMinor'] === 0 ? [] : $this->gateway->refunds($reference);
        $connection->transaction(function () use ($attempt, $checkout, $payment, $refunds, $recoverySessionId, $observedRefunds): void {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($current->getRawOriginal() !== $attempt->getRawOriginal()) {
                throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Payment changed during reconciliation; retry with fresh Stripe facts.');
            }
            $currentRefunds = PaymentRefund::query()->where('payment_attempt_id', $attempt->id)->orderBy('id')->lockForUpdate()->get()->map(fn (PaymentRefund $refund) => $refund->getRawOriginal())->all();
            if ($currentRefunds !== $observedRefunds) {
                throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Refunds changed during reconciliation; retry with fresh Stripe facts.');
            }
            if ($checkout !== null) {
                $this->applyCheckout($current, $checkout, $recoverySessionId !== null);
            }
            if ($payment !== null) {
                $this->syncer->assertMatches($current, $payment);
                if ($current->stripe_payment_intent_id === null && $payment['paymentIntentId'] !== null) {
                    $current->update(['stripe_payment_intent_id' => $payment['paymentIntentId']]);
                }
                if ($current->stripe_charge_id === null && $payment['chargeId'] !== null) {
                    $current->update(['stripe_charge_id' => $payment['chargeId']]);
                }
                $this->applyRefunds($current, $refunds);
                $this->syncer->reconcile($payment, $refunds);
            }
            $this->resolveOperations($current, $checkout, $payment);
        }, 5);

        return $attempt->refresh();
    }

    /**
     * Validate all immutable Checkout facts before correlating or releasing an attempt.
     *
     * @param  StripeCheckoutState  $checkout
     */
    private function assertCheckout(PaymentAttempt $attempt, array $checkout, string $sessionId): void
    {
        if ($attempt->origin !== 'checkout' || $checkout['sessionId'] !== $sessionId
            || ($attempt->stripe_checkout_session_id !== null && $checkout['sessionId'] !== $attempt->stripe_checkout_session_id)
            || $checkout['accountId'] !== config('nvl-payments.stripe.account_id') || $checkout['livemode'] !== config('nvl-payments.stripe.livemode')
            || ($attempt->stripe_account_id !== null && $checkout['accountId'] !== $attempt->stripe_account_id)
            || ($attempt->stripe_livemode !== null && $checkout['livemode'] !== $attempt->stripe_livemode)
            || $checkout['orderReference'] !== $attempt->order_reference || $checkout['orderRevision'] !== $attempt->order_revision
            || $checkout['amountMinor'] !== $attempt->amount_minor || strtoupper($checkout['currency']) !== strtoupper($attempt->currency)
            || ($attempt->stripe_payment_intent_id !== null && $checkout['paymentIntentId'] !== $attempt->stripe_payment_intent_id)) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Checkout does not match its reserved payment.');
        }
    }

    /**
     * Persist recovered identity only when the Session proves the original operation key.
     *
     * @param  StripeCheckoutState  $checkout
     */
    private function applyCheckout(PaymentAttempt $attempt, array $checkout, bool $recovery): void
    {
        if ($recovery) {
            $operation = PaymentOperation::query()->where('payment_attempt_id', $attempt->id)->where('type', 'checkout')->lockForUpdate()->sole();
            if ($checkout['operationKey'] === null || ! hash_equals($operation->idempotency_key, $checkout['operationKey'])) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Checkout recovery requires the original operation metadata.');
            }
        }
        $attempt->fill(['stripe_checkout_session_id' => $checkout['sessionId'], 'stripe_account_id' => $checkout['accountId'], 'stripe_livemode' => $checkout['livemode'], 'expires_at' => $checkout['expiresAt'], 'last_synced_at' => now()]);
        if ($checkout['url'] !== null) {
            $attempt->checkout_url = $checkout['url'];
        }
        if (in_array($attempt->state, ['reserved', 'open', 'checking', 'expiring', 'unknown', 'expired', 'failed'], true) && $attempt->captured_amount_minor === 0) {
            if ($checkout['status'] === 'expired' && $checkout['paymentStatus'] === 'unpaid' && $checkout['paymentIntentId'] === null) {
                $attempt->state = 'expired';
                $attempt->reservation_key = null;
            } elseif ($checkout['status'] === 'complete' || $checkout['paymentStatus'] !== 'unpaid') {
                $attempt->state = 'processing';
            } elseif ($checkout['status'] === 'open') {
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
            if (isset($seen[$remote['refundId']])) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Duplicate Stripe refund identity.');
            }
            $seen[$remote['refundId']] = true;
            $local = PaymentRefund::query()->where('stripe_refund_id', $remote['refundId'])->lockForUpdate()->first();
            if ($local !== null && $local->payment_attempt_id !== $attempt->id) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Refund belongs to another payment.');
            }
            if ($remote['operationKey'] !== null) {
                $correlated = PaymentOperation::query()->where('idempotency_key', $remote['operationKey'])->lockForUpdate()->first();
                if ($correlated === null || $correlated->type !== 'refund' || $correlated->payment_attempt_id !== $attempt->id || $correlated->order_reference !== $attempt->order_reference) {
                    throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Refund operation metadata does not match its payment.');
                }
                $reserved = PaymentRefund::query()->where('payment_operation_id', $correlated->id)->lockForUpdate()->sole();
                if ($local !== null && $local->id !== $reserved->id) {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Refund identity is already assigned to another reservation.');
                }
                $local = $reserved;
            }
            $this->balance->assertMatches($attempt, $remote, $local);
            if ($local === null) {
                $operation = $this->journal->reserve('reconcile_refund', $attempt->order_reference, (string) Str::uuid(), 'system:reconciliation', $remote['refundId'], $remote['amountMinor']);
                $operation->update(['payment_attempt_id' => $attempt->id]);
                $local = $this->refundWriter->create(['payment_attempt_id' => $attempt->id, 'payment_operation_id' => $operation->id, 'stripe_refund_id' => $remote['refundId'], 'amount_minor' => $remote['amountMinor'], 'currency' => strtoupper($remote['currency']), 'reason' => $remote['reason'], 'status' => $remote['status'], 'last_synced_at' => now()]);
            } else {
                if (in_array($local->status, ['failed', 'canceled'], true) && $local->status !== $remote['status']) {
                    throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'A terminal refund changed unexpectedly.');
                }
                $local->update(['stripe_refund_id' => $remote['refundId'], 'status' => $remote['status'], 'last_synced_at' => now()]);
                $operation = PaymentOperation::query()->findOrFail($local->payment_operation_id);
            }
            $this->journal->complete($operation, $remote['refundId']);
        }
    }

    /**
     * Close journal entries only when the exact remote object confirms a final outcome.
     *
     * @param  StripeCheckoutState|null  $checkout
     * @param  StripePaymentState|null  $payment
     */
    private function resolveOperations(PaymentAttempt $attempt, ?array $checkout, ?array $payment): void
    {
        foreach (PaymentOperation::query()->where('payment_attempt_id', $attempt->id)->whereIn('status', ['reserved', 'unknown'])->lockForUpdate()->get() as $operation) {
            $reference = match ($operation->type) {
                'checkout' => ($checkout['sessionId'] ?? null),
                'expire_checkout' => ($checkout['status'] ?? null) === 'expired' && $checkout['paymentStatus'] === 'unpaid' ? $checkout['sessionId'] : null,
                'capture' => ($payment['status'] ?? null) === 'succeeded' && $payment['capturedAmountMinor'] > 0 && $payment['amountCapturableMinor'] === 0 ? $payment['paymentIntentId'] : null,
                'cancel_authorization' => ($payment['status'] ?? null) === 'canceled' && $payment['capturedAmountMinor'] === 0 ? $payment['paymentIntentId'] : null,
                default => null,
            };
            if ($reference !== null) {
                $this->journal->complete($operation, $reference);
            }
        }
    }
}
