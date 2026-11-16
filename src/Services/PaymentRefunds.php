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
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Exceptions\ProviderRefused;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Refunds an internal payment ID through the approved primitive-input and immutable-snapshot package contract.
 *
 * @api
 */
class PaymentRefunds
{
    /** Inject trusted host admission, remote facts, and refund reservation boundaries. */
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly PaymentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentStateSyncer $syncer,
        private readonly RefundBalance $balance,
        private readonly PaymentOrderOwnership $scope,
        private readonly PaymentRefundWriter $refundWriter,
        private readonly ?PaymentLedgerOutbox $outbox = null,
    ) {}

    /** Reserve refundable funds atomically and issue one idempotent refund to the original payment method. */
    public function refund(PaymentAttempt|string $attempt, int $amountMinor, string $reason, ?string $note, string $operationId, Authenticatable $actor): PaymentRefund
    {
        $chargeReference = is_string($attempt) && preg_match('/^ch_[a-zA-Z0-9]+$/D', $attempt) === 1 ? $attempt : null;
        if ($chargeReference !== null) {
            $attempt = PaymentsModelRegistry::attemptQuery()->where('stripe_charge_id', $chargeReference)->firstOrFail();
        }
        $attemptId = $attempt instanceof PaymentAttempt ? $attempt->id : $attempt;
        $attempt = PaymentsModelRegistry::findAttemptOrFail($attemptId);
        $order = $this->orders->resolve($attempt->order_reference);
        $actor = $this->scope->resolveActor($order, $actor);
        $this->access->assertCanManage($actor, 'refund', $order);
        if (! config()->boolean('nvl-payments.enabled')) {
            throw PaymentsException::because(PaymentsResponseCode::FeatureDisabled, 'Payments is disabled.');
        }
        if ($order->reference !== $attempt->order_reference) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Order provider returned a different order.');
        }
        if ($amountMinor > $attempt->amount_minor) {
            throw PaymentsException::because(PaymentsResponseCode::RefundBalanceExceeded, 'Refund exceeds the original payment amount.');
        }
        $actorId = $actor->getAuthIdentifier();
        if ($amountMinor <= 0 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}$/D', $reason) !== 1
            || ! Str::isUuid($operationId) || (! is_string($actorId) && ! is_int($actorId)) || trim((string) $actorId) === '') {
            throw new InvalidArgumentException('A valid refund amount, reason, operation UUID, and stable actor are required.');
        }
        if ($note !== null && (strlen($note) > 65535 || str_contains($note, "\0") || preg_match('//u', $note) !== 1)) {
            throw new InvalidArgumentException('Refund note must be valid UTF-8 without NUL and at most 65535 bytes.');
        }
        $connection = $attempt->getConnection();
        if ($connection->transactionLevel() !== 0) {
            throw new DomainException('Refunds must start outside a database transaction.');
        }
        $stripeReason = RefundBalance::providerReason($reason);
        $fingerprint = hash('sha256', json_encode([$attemptId, $reason, $note], JSON_THROW_ON_ERROR));
        if (PaymentOperation::query()->whereKey($operationId)->exists()) {
            $operation = $this->journal->reserve('refund', $attempt->order_reference, $operationId, (string) $actorId, $fingerprint, $amountMinor);

            return $this->replay($operation);
        }
        $reference = $chargeReference ?? $attempt->stripe_payment_intent_id ?? $attempt->stripe_charge_id ?? throw new DomainException('Missing Stripe payment identity.');
        $observedRefunds = PaymentsModelRegistry::refundQuery()->where('payment_attempt_id', $attempt->id)
            ->orderBy('id')->get()->map(fn (PaymentRefund $refund) => $refund->getRawOriginal())->all();
        $payment = $this->gateway->payment($reference);
        if ($this->syncer->exceptionReason($attempt, $payment) !== null) {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Resolve the payment exception before refunding.');
        }
        $this->syncer->assertMatches($attempt, $payment);
        $refunds = $this->gateway->refunds($reference);
        $operation = $connection->transaction(function () use ($attempt, $operationId, $actorId, $fingerprint, $amountMinor, $reason, $note, $payment, $refunds, $stripeReason, $observedRefunds): PaymentOperation {
            // Acquire SQLite's write lock before reading the balance; PostgreSQL locks this attempt row.
            PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->update(['id' => $attempt->id]);
            $current = PaymentsModelRegistry::attemptQuery()->lockForUpdate()->findOrFail($attempt->id);
            $operation = $this->journal->reserve('refund', $current->order_reference, $operationId, (string) $actorId, $fingerprint, $amountMinor);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $this->syncer->assertMatches($current, $payment);
            if ($this->syncer->exceptionReason($current, $payment) !== null) {
                throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Resolve the payment exception before refunding.');
            }
            $currentRefunds = PaymentsModelRegistry::refundQuery()->where('payment_attempt_id', $current->id)
                ->orderBy('id')->lockForUpdate()->get()->map(fn (PaymentRefund $refund) => $refund->getRawOriginal())->all();
            $observedById = array_column($observedRefunds, null, 'id');
            foreach ($currentRefunds as $currentRefund) {
                $id = $currentRefund['id'];
                $observed = is_string($id) ? ($observedById[$id] ?? null) : null;
                if ($observed !== null && $currentRefund !== $observed) {
                    throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Refunds changed after the Stripe read.');
                }
            }
            $this->syncer->sync($payment);
            if ($amountMinor > $this->balance->available($current, $payment, $refunds, $attempt->refunded_amount_minor)) {
                throw PaymentsException::because(PaymentsResponseCode::RefundBalanceExceeded, 'Refund exceeds the available captured balance.');
            }
            $operation->update(['payment_attempt_id' => $current->id]);
            $this->refundWriter->create(['payment_attempt_id' => $current->id, 'payment_operation_id' => $operation->id,
                'amount_minor' => $amountMinor, 'currency' => $current->currency, 'reason' => $reason, 'stripe_reason' => $stripeReason, 'internal_note' => $note, 'status' => 'reserved']);

            return $operation;
        }, 5);
        if (! $operation->wasRecentlyCreated) {
            return $this->replay($operation);
        }
        $refund = PaymentsModelRegistry::refundQuery()->where('payment_operation_id', $operation->id)->sole();
        try {
            $result = $this->gateway->refund($reference, $amountMinor, $stripeReason ?? $reason, $operation->idempotency_key);
            $this->balance->assertMatches($attempt, $result, $refund);
            $connection->transaction(function () use ($attempt, $refund, $result, $operation): void {
                PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->update(['id' => $attempt->id]);
                $current = PaymentsModelRegistry::refundQuery()->lockForUpdate()->findOrFail($refund->id);
                $this->balance->assertMatches($attempt, $result, $current);
                // A linked refund has already been observed by reconciliation after creation.
                if ($current->stripe_refund_id === null) {
                    $current->update(['stripe_refund_id' => $result['refundId'], 'status' => $result['status'], 'ledger_transition' => 1, 'last_synced_at' => now()]);
                    ($this->outbox ?? app(PaymentLedgerOutbox::class))->refund($attempt, $current, $result);
                }
                $this->journal->complete($operation, $result['refundId']);
            }, 5);
        } catch (InvalidRequestException|ProviderRefused $exception) {
            $code = $exception instanceof ProviderRefused ? $exception->stripeCode : $exception->getStripeCode();
            $status = $exception instanceof ProviderRefused ? $exception->httpStatus : $exception->getHttpStatus();
            if ($status === 400 && in_array($code, ['charge_already_refunded', 'amount_too_large'], true)) {
                $connection->transaction(function () use ($attempt, $refund, $operation): void {
                    PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->update(['id' => $attempt->id]);
                    $refund->update(['status' => 'failed', 'last_synced_at' => now()]);
                    $operation->update(['status' => 'failed', 'resolved_at' => now()]);
                }, 5);
                $latest = $this->gateway->payment($reference);
                $this->syncer->assertMatches($attempt, $latest);
                $this->syncer->sync($latest);
            } else {
                $this->journal->markUnknown($operation);
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->journal->markUnknown($operation);
            throw $exception;
        }

        return $refund->refresh();
    }

    /** Return a confirmed result and leave ambiguous operations blocked for reconciliation. */
    private function replay(PaymentOperation $operation): PaymentRefund
    {
        if (! in_array($operation->status, ['completed', 'failed'], true)) {
            throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Refund operation is unresolved; reconcile before retrying.');
        }

        return PaymentsModelRegistry::refundQuery()->where('payment_operation_id', $operation->id)->sole();
    }
}
