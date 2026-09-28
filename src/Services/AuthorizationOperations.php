<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\ValueObjects\PaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Throwable;

/**
 * Owns the shared durable boundary for mutually exclusive authorization operations.
 * Transactions intentionally live here so capture and cancellation share one claim protocol.
 */
final class AuthorizationOperations
{
    /** Inject trusted host admission, Stripe facts, and durable payment state boundaries. */
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly PaymentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentStateSyncer $syncer,
    ) {}

    /** Reserve one final authorization operation before making remote calls outside transactions. */
    public function execute(string $kind, string $attemptId, Authenticatable $actor, ?int $amountMinor, string $operationId): PaymentSnapshot
    {
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $order = $this->orders->resolve($attempt->order_reference);
        $this->access->assertCanManage($actor, $kind, $order);
        if (! config()->boolean('payments.enabled') || $order->reference !== $attempt->order_reference) {
            throw new DomainException('Payments must be enabled for the requested order.');
        }
        if (! in_array($kind, ['capture', 'cancel_authorization'], true)
            || ($kind === 'capture' && ($amountMinor === null || $amountMinor <= 0 || $amountMinor > $attempt->amount_minor))
            || ($kind === 'cancel_authorization' && $amountMinor !== null)) {
            throw new InvalidArgumentException('Invalid authorization operation or amount.');
        }
        $actorId = $actor->getAuthIdentifier();
        if ((! is_string($actorId) && ! is_int($actorId)) || trim((string) $actorId) === '') {
            throw new InvalidArgumentException('A stable actor identifier is required.');
        }
        $connection = $attempt->getConnection();
        if ($connection->transactionLevel() !== 0) {
            throw new DomainException('Authorization operations must start outside a database transaction.');
        }
        $operation = $connection->transaction(function () use ($attempt, $kind, $operationId, $actorId, $amountMinor): PaymentOperation {
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $operation = $this->journal->reserve($kind, $current->order_reference, $operationId, (string) $actorId, $current->id, $amountMinor);
            if (! $operation->wasRecentlyCreated) {
                if ($operation->status !== 'completed') {
                    throw new DomainException('Authorization operation is unresolved; reconcile before retrying.');
                }

                return $operation;
            }
            if ($current->state !== 'authorized' || $current->capture_method !== 'manual' || $current->captured_amount_minor !== 0
                || $current->stripe_payment_intent_id === null || ! str_starts_with($current->stripe_payment_intent_id, 'pi_')) {
                throw new DomainException('Attempt is not an available PaymentIntent authorization.');
            }
            if (PaymentOperation::query()->where('payment_attempt_id', $current->id)->whereIn('type', ['capture', 'cancel_authorization'])->whereIn('status', ['reserved', 'unknown'])->exists()) {
                throw new DomainException('Another authorization operation requires reconciliation.');
            }
            $operation->update(['payment_attempt_id' => $current->id]);

            return $operation;
        }, 5);
        if (! $operation->wasRecentlyCreated) {
            return $this->snapshot($attempt->refresh());
        }
        try {
            $intentId = $attempt->stripe_payment_intent_id ?? throw new DomainException('Missing PaymentIntent.');
            $remote = $this->gateway->payment($intentId);
            $this->syncer->assertMatches($attempt, $remote);
            $this->assertSupported($remote);
            $result = $kind === 'capture'
                ? $this->gateway->capture($intentId, $amountMinor, $operation->idempotency_key)
                : $this->gateway->cancel($intentId, $operation->idempotency_key);
            $this->syncer->assertMatches($attempt, $result);
            if (($kind === 'capture' && ($result->status !== 'succeeded' || $result->capturedAmountMinor !== $amountMinor || $result->amountCapturableMinor !== 0))
                || ($kind === 'cancel_authorization' && ($result->status !== 'canceled' || $result->capturedAmountMinor !== 0 || $result->amountCapturableMinor !== 0))) {
                throw new DomainException('Stripe did not confirm the requested final authorization operation.');
            }
            $connection->transaction(function () use ($result, $operation, $intentId): void {
                $this->syncer->sync($result);
                $this->journal->complete($operation, $intentId);
            }, 5);

            return $this->snapshot($attempt->refresh());
        } catch (Throwable $exception) {
            $this->journal->markUnknown($operation);
            throw $exception;
        }
    }

    /** Reject partial, previously captured, unsupported, or unavailable authorizations. */
    private function assertSupported(StripePaymentState $payment): void
    {
        if ($payment->status !== 'requires_capture' || $payment->captureMethod !== 'manual' || $payment->paymentMethodType !== 'card'
            || $payment->capturedAmountMinor !== 0 || $payment->refundedAmountMinor !== 0 || $payment->amountCapturableMinor !== $payment->amountMinor
            || ($payment->partialAuthorizationStatus !== null && ! in_array($payment->partialAuthorizationStatus, ['fully_authorized', 'not_requested'], true))) {
            throw new DomainException('Stripe authorization is not supported or no longer available.');
        }
    }

    /** Return persisted facts including the synchronization timestamps. */
    private function snapshot(PaymentAttempt $attempt): PaymentSnapshot
    {
        return new PaymentSnapshot($attempt->id, $attempt->order_reference, $attempt->order_revision, $attempt->amount_minor,
            $attempt->captured_amount_minor, $attempt->refunded_amount_minor, $attempt->currency, $attempt->state, $attempt->origin,
            $attempt->stripe_checkout_session_id, $attempt->stripe_payment_intent_id, $attempt->stripe_charge_id, $attempt->stripe_account_id,
            $attempt->stripe_livemode, $attempt->capture_method,
            $attempt->expires_at === null ? null : CarbonImmutable::instance($attempt->expires_at),
            $attempt->last_synced_at === null ? null : CarbonImmutable::instance($attempt->last_synced_at));
    }
}
