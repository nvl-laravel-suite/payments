<?php

declare(strict_types=1);

namespace Nvl\Payments\Actions;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Contracts\RefundPaymentContract;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Services\PaymentOperationJournal;
use Nvl\Payments\Services\PaymentStateSyncer;
use Nvl\Payments\Services\RefundBalance;
use Nvl\Payments\ValueObjects\RefundSnapshot;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Refunds an internal payment ID through the approved primitive-input and immutable-snapshot package contract.
 *
 * @api
 */
final class RefundPaymentAction implements RefundPaymentContract
{
    /** Inject trusted host admission, remote facts, and refund reservation boundaries. */
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly PaymentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentStateSyncer $syncer,
        private readonly RefundBalance $balance,
    ) {}

    /** Reserve refundable funds atomically and issue one idempotent refund to the original payment method. */
    public function execute(string $attemptId, Authenticatable $actor, int $amountMinor, string $reason, ?string $note, string $operationId): RefundSnapshot
    {
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $order = $this->orders->resolve($attempt->order_reference);
        $this->access->assertCanManage($actor, 'refund', $order);
        if (! config()->boolean('nvl-payments.enabled') || $order->reference !== $attempt->order_reference) {
            throw new DomainException('Payments must be enabled for the requested order.');
        }
        $actorId = $actor->getAuthIdentifier();
        if ($amountMinor <= 0 || $amountMinor > $attempt->amount_minor || ! in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)
            || ! Str::isUuid($operationId) || (! is_string($actorId) && ! is_int($actorId)) || trim((string) $actorId) === '') {
            throw new InvalidArgumentException('A valid refund amount, reason, operation UUID, and stable actor are required.');
        }
        $connection = $attempt->getConnection();
        if ($connection->transactionLevel() !== 0) {
            throw new DomainException('Refunds must start outside a database transaction.');
        }
        $fingerprint = hash('sha256', json_encode([$attemptId, $reason, $note], JSON_THROW_ON_ERROR));
        if (PaymentOperation::query()->whereKey($operationId)->exists()) {
            $operation = $this->journal->reserve('refund', $attempt->order_reference, $operationId, (string) $actorId, $fingerprint, $amountMinor);

            return $this->replay($operation);
        }
        $reference = $attempt->stripe_payment_intent_id ?? $attempt->stripe_charge_id ?? throw new DomainException('Missing Stripe payment identity.');
        $payment = $this->gateway->payment($reference);
        $this->syncer->assertMatches($attempt, $payment);
        $refunds = $this->gateway->refunds($reference);
        $operation = $connection->transaction(function () use ($attempt, $operationId, $actorId, $fingerprint, $amountMinor, $reason, $note, $payment, $refunds): PaymentOperation {
            // Acquire SQLite's write lock before reading the balance; PostgreSQL locks this attempt row.
            PaymentAttempt::query()->whereKey($attempt->id)->update(['id' => $attempt->id]);
            $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $operation = $this->journal->reserve('refund', $current->order_reference, $operationId, (string) $actorId, $fingerprint, $amountMinor);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $this->syncer->assertMatches($current, $payment);
            if ($amountMinor > $this->balance->available($current, $payment, $refunds, $attempt->refunded_amount_minor)) {
                throw new DomainException('Refund exceeds the available captured balance.');
            }
            $operation->update(['payment_attempt_id' => $current->id]);
            PaymentRefund::query()->create(['payment_attempt_id' => $current->id, 'payment_operation_id' => $operation->id,
                'amount_minor' => $amountMinor, 'currency' => $current->currency, 'reason' => $reason, 'internal_note' => $note, 'status' => 'reserved']);

            return $operation;
        }, 5);
        if (! $operation->wasRecentlyCreated) {
            return $this->replay($operation);
        }
        $refund = PaymentRefund::query()->where('payment_operation_id', $operation->id)->sole();
        try {
            $result = $this->gateway->refund($reference, $amountMinor, $reason, $operation->idempotency_key);
            $this->balance->assertMatches($attempt, $result, $refund);
            $connection->transaction(function () use ($attempt, $refund, $result, $operation): void {
                PaymentAttempt::query()->whereKey($attempt->id)->update(['id' => $attempt->id]);
                $current = PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id);
                $this->balance->assertMatches($attempt, $result, $current);
                // A linked refund has already been observed by reconciliation after creation.
                if ($current->stripe_refund_id === null) {
                    $current->update(['stripe_refund_id' => $result->refundId, 'status' => $result->status, 'last_synced_at' => now()]);
                }
                $this->journal->complete($operation, $result->refundId);
            }, 5);
        } catch (InvalidRequestException $exception) {
            if ($exception->getHttpStatus() === 400 && in_array($exception->getStripeCode(), ['charge_already_refunded', 'amount_too_large'], true)) {
                $connection->transaction(function () use ($attempt, $refund, $operation): void {
                    PaymentAttempt::query()->whereKey($attempt->id)->update(['id' => $attempt->id]);
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

        return $this->snapshot($refund->refresh());
    }

    /** Return a confirmed result and leave ambiguous operations blocked for reconciliation. */
    private function replay(PaymentOperation $operation): RefundSnapshot
    {
        if (! in_array($operation->status, ['completed', 'failed'], true)) {
            throw new DomainException('Refund operation is unresolved; reconcile before retrying.');
        }

        return $this->snapshot(PaymentRefund::query()->where('payment_operation_id', $operation->id)->sole());
    }

    /** Return persisted refund facts with pending distinct from success. */
    private function snapshot(PaymentRefund $refund): RefundSnapshot
    {
        return new RefundSnapshot($refund->id, $refund->payment_attempt_id, $refund->payment_operation_id, $refund->amount_minor,
            $refund->currency, $refund->reason, $refund->status, $refund->stripe_refund_id,
            $refund->last_synced_at === null ? null : CarbonImmutable::instance($refund->last_synced_at));
    }
}
