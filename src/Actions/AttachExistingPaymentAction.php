<?php

declare(strict_types=1);

namespace Nvl\Payments\Actions;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nvl\Payments\Contracts\AttachExistingPaymentContract;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\PaymentOperationJournal;
use Nvl\Payments\Services\PaymentStateSyncer;
use Nvl\Payments\ValueObjects\PaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Throwable;

/**
 * Attaches a host-proven Stripe payment without creating or changing the host Order.
 * The primitive signature and snapshot return are the explicit standalone host contract.
 * Local transactions bracket the remote read and the host ownership proof.
 *
 * @api
 */
final class AttachExistingPaymentAction implements AttachExistingPaymentContract
{
    /** Inject trusted host boundaries and the shared payment journal and state writer. */
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly ExistingPaymentOwnership $ownership,
        private readonly PaymentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentStateSyncer $syncer,
    ) {}

    /** Attach canonical Stripe facts once after explicit host authorization and ownership proof. */
    public function execute(string $orderReference, Authenticatable $actor, string $stripeReference, string $operationId): PaymentSnapshot
    {
        $order = $this->orders->resolve($orderReference);
        $this->access->assertCanManage($actor, 'attach_existing', $order);
        if (! config()->boolean('nvl-payments.enabled')) {
            throw PaymentsException::because(PaymentsResponseCode::FeatureDisabled, 'Payments is disabled.');
        }
        if ($order->reference !== $orderReference) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Order provider returned a different order.');
        }
        if (! in_array(strtoupper($order->currency), config()->array('nvl-payments.allowed_currencies'), true)) {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'The requested order currency is unavailable.');
        }
        $actorId = $actor->getAuthIdentifier();
        if ((! is_string($actorId) && ! is_int($actorId)) || trim((string) $actorId) === ''
            || preg_match('/^(pi|ch|txn)_[A-Za-z0-9]+$/D', $stripeReference) !== 1) {
            throw new InvalidArgumentException('A stable actor and supported Stripe reference are required.');
        }
        $connection = (new PaymentAttempt)->getConnection();
        if ($connection->transactionLevel() !== 0) {
            throw new DomainException('Payment attachment must start outside a database transaction.');
        }
        $operation = $connection->transaction(fn (): PaymentOperation => $this->journal->reserve(
            'attach_existing', $order->reference, $operationId, (string) $actorId,
            hash('sha256', json_encode([$stripeReference, $order->revision, strtoupper($order->currency)], JSON_THROW_ON_ERROR)), $order->amountMinor,
        ), 5);
        if (! $operation->wasRecentlyCreated) {
            if ($operation->status !== 'completed' || $operation->payment_attempt_id === null) {
                throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Attachment is unresolved; reconcile before retrying.');
            }

            return $this->snapshot(PaymentAttempt::query()->findOrFail($operation->payment_attempt_id));
        }
        try {
            $payment = $this->gateway->resolveExisting($stripeReference);
            $this->assertIdentity($stripeReference, $payment);
            $attempt = new PaymentAttempt([
                'order_reference' => $order->reference, 'order_revision' => $order->revision,
                'amount_minor' => $order->amountMinor, 'currency' => strtoupper($order->currency),
                'origin' => 'attached', 'state' => 'reserved',
                'stripe_payment_intent_id' => $payment->paymentIntentId, 'stripe_charge_id' => $payment->chargeId,
            ]);
            $this->syncer->assertMatches($attempt, $payment);
            $this->ownership->assertOwned($order, $payment);
            $connection->transaction(function () use ($attempt, $operation, $payment): void {
                $current = PaymentOperation::query()->lockForUpdate()->findOrFail($operation->id);
                if ($current->status !== 'reserved') {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Attachment operation changed while resolving Stripe.');
                }
                $duplicate = PaymentAttempt::query()->where(function ($query) use ($payment): void {
                    if ($payment->paymentIntentId !== null) {
                        $query->orWhere('stripe_payment_intent_id', $payment->paymentIntentId);
                    }
                    if ($payment->chargeId !== null) {
                        $query->orWhere('stripe_charge_id', $payment->chargeId);
                    }
                })->exists();
                if ($duplicate) {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'This canonical Stripe payment is already attached.');
                }
                $attempt->save();
                $current->update(['payment_attempt_id' => $attempt->id]);
                $this->syncer->sync($payment);
                $this->journal->complete($current, $payment->paymentIntentId ?? $payment->chargeId ?? throw new DomainException('Missing canonical payment identity.'));
            }, 5);

            return $this->snapshot($attempt->refresh());
        } catch (UniqueConstraintViolationException $exception) {
            $this->journal->markUnknown($operation);
            throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'This canonical Stripe payment was attached concurrently.', previous: $exception);
        } catch (Throwable $exception) {
            $this->journal->markUnknown($operation);
            throw $exception;
        }
    }

    /** Reject missing, substituted, or unsupported canonical payment identities and state. */
    private function assertIdentity(string $reference, StripePaymentState $payment): void
    {
        if (($payment->paymentIntentId === null && $payment->chargeId === null)
            || ($payment->paymentIntentId !== null && preg_match('/^pi_[A-Za-z0-9]+$/D', $payment->paymentIntentId) !== 1)
            || ($payment->chargeId !== null && preg_match('/^ch_[A-Za-z0-9]+$/D', $payment->chargeId) !== 1)
            || (str_starts_with($reference, 'pi_') && $payment->paymentIntentId !== $reference)
            || ! in_array($payment->status, ['succeeded', 'requires_capture', 'processing', 'requires_payment_method', 'failed', 'canceled', 'requires_action', 'requires_confirmation'], true)) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Stripe did not resolve a supported canonical payment.');
        }
    }

    /** Return persisted facts after synchronization and operation completion commit. */
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
