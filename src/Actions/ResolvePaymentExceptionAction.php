<?php

declare(strict_types=1);

namespace Nvl\Payments\Actions;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Events\PaymentStateChanged;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Services\PaymentOperationJournal;
use Nvl\Payments\Services\PaymentProjection;
use Nvl\Payments\Services\PaymentStateSyncer;
use Nvl\Payments\ValueObjects\PaymentSnapshot;

/** Accepts exception money explicitly through the primitive-input host contract without rewriting its original order revision. */
final class ResolvePaymentExceptionAction
{
    /** Inject host admission, authoritative financial facts, and durable acceptance auditing. */
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly PaymentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentStateSyncer $syncer,
        private readonly PaymentProjection $projection,
    ) {}

    /** Accept a confirmed payment exception once with an immutable actor and operation UUID. */
    public function execute(string $attemptId, Authenticatable $actor, string $operationId): PaymentSnapshot
    {
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $order = $this->orders->resolve($attempt->order_reference);
        $this->access->assertCanManage($actor, 'resolve_payment_exception', $order);
        $connection = $attempt->getConnection();
        if (! config()->boolean('nvl-payments.enabled') || $order->reference !== $attempt->order_reference || $connection->transactionLevel() !== 0) {
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
                throw new DomainException('Exception acceptance is unresolved.');
            }
        } else {
            $reference = $attempt->stripe_payment_intent_id ?? $attempt->stripe_charge_id ?? throw new DomainException('Missing Stripe payment identity.');
            $payment = $this->gateway->payment($reference);
            $this->syncer->assertMatches($attempt, $payment);
            if ($payment->status !== 'succeeded' || $payment->capturedAmountMinor <= 0) {
                throw new DomainException('Only confirmed captured money can be accepted.');
            }
            $connection->transaction(function () use ($attempt, $payment, $actorId, $operationId, $connection): void {
                $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($current->getRawOriginal() !== $attempt->getRawOriginal() || $current->state !== 'payment_exception') {
                    throw new DomainException('Payment exception changed; review its current facts before accepting.');
                }
                $operation = $this->journal->reserve('resolve_payment_exception', $current->order_reference, $operationId, (string) $actorId, $current->id, null);
                $operation->update(['payment_attempt_id' => $current->id]);
                $this->syncer->sync($payment);
                $current->refresh();
                $state = $current->refunded_amount_minor > 0 ? ($current->refunded_amount_minor === $current->captured_amount_minor ? 'refunded' : 'partially_refunded') : 'captured';
                $current->update(['state' => $state]);
                $this->journal->complete($operation, $current->stripe_payment_intent_id ?? $current->stripe_charge_id ?? $current->id);
                $event = new PaymentStateChanged($current->order_reference, $current->id, 'payment_exception', $state, $current->stripe_payment_intent_id, $current->stripe_charge_id, $current->stripe_checkout_session_id);
                $connection->afterCommit(static fn () => Event::dispatch($event));
            }, 5);
        }

        return $this->projection->payment($attempt->refresh(), PaymentRefund::query()->where('payment_attempt_id', $attemptId)->get());
    }
}
