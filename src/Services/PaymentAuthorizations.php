<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Throwable;

/**
 * Owns the shared durable boundary for mutually exclusive authorization operations.
 * Transactions intentionally live here so capture and cancellation share one claim protocol.
 *
 * @api
 *
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 */
final class PaymentAuthorizations
{
    /** Inject trusted host admission, Stripe facts, and durable payment state boundaries. */
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly PaymentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentStateSyncer $syncer,
        private readonly PaymentOrderOwnership $scope,
    ) {}

    /** Capture an authorized payment for the exact integer amount. */
    public function capture(PaymentAttempt|string $attempt, int $amountMinor, string $operationId, Authenticatable $actor): PaymentAttempt
    {
        return $this->execute('capture', $attempt instanceof PaymentAttempt ? $attempt->id : $attempt, $actor, $amountMinor, $operationId);
    }

    /** Cancel an uncaptured authorization. */
    public function cancel(PaymentAttempt|string $attempt, string $operationId, Authenticatable $actor): PaymentAttempt
    {
        return $this->execute('cancel_authorization', $attempt instanceof PaymentAttempt ? $attempt->id : $attempt, $actor, null, $operationId);
    }

    /** Reserve one final authorization operation before making remote calls outside transactions. */
    private function execute(string $kind, string $attemptId, Authenticatable $actor, ?int $amountMinor, string $operationId): PaymentAttempt
    {
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $order = $this->orders->resolve($attempt->order_reference);
        $this->scope->assertOrder($order, $actor);
        $this->access->assertCanManage($actor, $kind, $order);
        if (! config()->boolean('nvl-payments.enabled')) {
            throw PaymentsException::because(PaymentsResponseCode::FeatureDisabled, 'Payments is disabled.');
        }
        if ($order->reference !== $attempt->order_reference) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Order provider returned a different order.');
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
                    throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Authorization operation is unresolved; reconcile before retrying.');
                }

                return $operation;
            }
            if ($current->state !== 'authorized' || $current->capture_method !== 'manual' || $current->captured_amount_minor !== 0
                || $current->stripe_payment_intent_id === null || ! str_starts_with($current->stripe_payment_intent_id, 'pi_')) {
                throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Attempt is not an available PaymentIntent authorization.');
            }
            if (PaymentOperation::query()->where('payment_attempt_id', $current->id)->whereIn('type', ['capture', 'cancel_authorization'])->whereIn('status', ['reserved', 'unknown'])->exists()) {
                throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Another authorization operation requires reconciliation.');
            }
            $operation->update(['payment_attempt_id' => $current->id]);

            return $operation;
        }, 5);
        if (! $operation->wasRecentlyCreated) {
            return $attempt->refresh();
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
            if (($kind === 'capture' && ($result['status'] !== 'succeeded' || $result['capturedAmountMinor'] !== $amountMinor || $result['amountCapturableMinor'] !== 0))
                || ($kind === 'cancel_authorization' && ($result['status'] !== 'canceled' || $result['capturedAmountMinor'] !== 0 || $result['amountCapturableMinor'] !== 0))) {
                throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Stripe did not confirm the requested final authorization operation.');
            }
            $connection->transaction(function () use ($result, $operation, $intentId): void {
                $this->syncer->sync($result);
                $this->journal->complete($operation, $intentId);
            }, 5);

            return $attempt->refresh();
        } catch (Throwable $exception) {
            $this->journal->markUnknown($operation);
            throw $exception;
        }
    }

    /**
     * Reject partial, previously captured, unsupported, or unavailable authorizations.
     *
     * @param  StripePaymentState  $payment
     */
    private function assertSupported(array $payment): void
    {
        if ($payment['status'] !== 'requires_capture' || $payment['captureMethod'] !== 'manual' || $payment['paymentMethodType'] !== 'card'
            || $payment['capturedAmountMinor'] !== 0 || $payment['refundedAmountMinor'] !== 0 || $payment['amountCapturableMinor'] !== $payment['amountMinor']
            || ($payment['partialAuthorizationStatus'] !== null && ! in_array($payment['partialAuthorizationStatus'], ['fully_authorized', 'not_requested'], true))) {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Stripe authorization is not supported or no longer available.');
        }
    }
}
