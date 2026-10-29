<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Throwable;

/**
 * Reserves one hosted checkout for a trusted host order reference.
 * Local transactions bracket remote calls; the explicit primitive signature is the host contract.
 *
 * @api
 *
 * @phpstan-import-type HostedCheckout from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripeCheckoutState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 */
final class PaymentCheckout
{
    /** Inject host authorization, trusted facts, and the durable Stripe boundary. */
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly ExistingPaymentOwnership $ownership,
        private readonly PaymentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentStateSyncer $syncer,
        private readonly PaymentReconciliation $reconciler,
        private readonly PaymentOrderOwnership $scope,
    ) {}

    /**
     * Return an existing safe URL or reserve and create a new Checkout Session.
     *
     * @return HostedCheckout
     */
    public function start(string $orderReference, Authenticatable $actor, string $successUrl, string $cancelUrl): array
    {
        $order = $this->orders->resolve($orderReference);
        $actor = $this->scope->resolveActor($order, $actor);
        $this->access->assertCanManage($actor, 'start_checkout', $order);
        $this->validate($order, $successUrl, $cancelUrl);
        $connection = (PaymentsModelRegistry::newAttempt())->getConnection();
        if ($connection->transactionLevel() !== 0) {
            throw new DomainException('Checkout must start outside an existing database transaction.');
        }
        $actorIdentifier = $actor->getAuthIdentifier();
        if ((! is_string($actorIdentifier) && ! is_int($actorIdentifier)) || trim((string) $actorIdentifier) === '') {
            throw new InvalidArgumentException('A stable actor identifier is required.');
        }
        $actorId = (string) $actorIdentifier;

        for ($iteration = 0; $iteration < 3; $iteration++) {
            $attempt = $connection->transaction(function () use ($order, $actorId, $successUrl, $cancelUrl): PaymentAttempt {
                $attempt = PaymentsModelRegistry::attemptQuery()->createOrFirst(['reservation_key' => $this->scope->reservationKey($order->reference)], [
                    'order_reference' => $order->reference, 'order_revision' => $order->revision,
                    'amount_minor' => $order->amountMinor, 'currency' => strtoupper($order->currency),
                    'origin' => 'checkout', 'state' => 'reserved',
                    'capture_method' => config('nvl-payments.checkout.capture_method', 'automatic'),
                    'expires_at' => CarbonImmutable::now()->addMinutes(config()->integer('nvl-payments.checkout.expires_in_minutes')),
                ]);
                if (PaymentsModelRegistry::attemptQuery()->where('order_reference', $order->reference)->whereKeyNot($attempt->id)->whereIn('state', ['processing', 'authorized', 'captured', 'partially_refunded', 'refunded', 'payment_exception'])->exists()) {
                    throw PaymentsException::because(PaymentsResponseCode::CheckoutConflict, 'An existing payment blocks another checkout.');
                }
                if ($attempt->wasRecentlyCreated) {
                    $operation = $this->journal->reserve('checkout', $order->reference, $attempt->id, $actorId, hash('sha256', json_encode([$order, $successUrl, $cancelUrl, $attempt->capture_method, $attempt->expires_at?->getTimestamp()], JSON_THROW_ON_ERROR)), $order->amountMinor);
                    $operation->update(['payment_attempt_id' => $attempt->id]);
                }

                return $attempt;
            }, 5);

            if ($attempt->wasRecentlyCreated) {
                return $this->create($attempt, $order, $successUrl, $cancelUrl);
            }
            $existing = $this->reuseOrExpire($attempt, $order, $actorId);
            if ($existing !== null) {
                return $existing;
            }
        }
        throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Checkout reservation changed concurrently; retry after reconciliation.');
    }

    /** Recover a supplied Stripe Session only after host authorization and remote proof. */
    public function recover(PaymentAttempt|string $attempt, string $stripeSessionId, Authenticatable $actor): PaymentAttempt
    {
        $attemptId = $attempt instanceof PaymentAttempt ? $attempt->id : $attempt;
        $attempt = PaymentsModelRegistry::attemptQuery()->findOrFail($attemptId);
        $order = $this->orders->resolve($attempt->order_reference);
        $actor = $this->scope->resolveActor($order, $actor);
        $this->access->assertCanManage($actor, 'recover_checkout', $order);
        if ($order->reference !== $attempt->order_reference) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Invalid Checkout recovery order identity.');
        }
        if (! str_starts_with($stripeSessionId, 'cs_')) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Invalid Checkout recovery Session identity.');
        }

        $this->reconciler->reconcile($attemptId, $stripeSessionId);

        return $attempt->refresh();
    }

    /** Attach canonical Stripe facts once after explicit host authorization and ownership proof. */
    public function attach(string $orderReference, string $stripeReference, string $operationId, Authenticatable $actor): PaymentAttempt
    {
        $order = $this->orders->resolve($orderReference);
        $actor = $this->scope->resolveActor($order, $actor);
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
        $connection = (PaymentsModelRegistry::newAttempt())->getConnection();
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

            return PaymentsModelRegistry::attemptQuery()->findOrFail($operation->payment_attempt_id);
        }
        try {
            $payment = $this->gateway->resolveExisting($stripeReference);
            $this->assertIdentity($stripeReference, $payment);
            $attempt = PaymentsModelRegistry::newAttempt([
                'order_reference' => $order->reference, 'order_revision' => $order->revision,
                'amount_minor' => $order->amountMinor, 'currency' => strtoupper($order->currency),
                'origin' => 'attached', 'state' => 'reserved',
                'stripe_payment_intent_id' => $payment['paymentIntentId'], 'stripe_charge_id' => $payment['chargeId'],
            ]);
            $this->syncer->assertMatches($attempt, $payment);
            $this->ownership->assertOwned($order, $payment);
            $connection->transaction(function () use ($attempt, $operation, $payment): void {
                $current = PaymentOperation::query()->lockForUpdate()->findOrFail($operation->id);
                if ($current->status !== 'reserved') {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Attachment operation changed while resolving Stripe.');
                }
                $duplicate = PaymentsModelRegistry::attemptQuery()->where(function ($query) use ($payment): void {
                    if ($payment['paymentIntentId'] !== null) {
                        $query->orWhere('stripe_payment_intent_id', $payment['paymentIntentId']);
                    }
                    if ($payment['chargeId'] !== null) {
                        $query->orWhere('stripe_charge_id', $payment['chargeId']);
                    }
                })->exists();
                if ($duplicate) {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'This canonical Stripe payment is already attached.');
                }
                $attempt->save();
                $current->update(['payment_attempt_id' => $attempt->id]);
                $this->syncer->sync($payment);
                $this->journal->complete($current, $payment['paymentIntentId'] ?? $payment['chargeId'] ?? throw new DomainException('Missing canonical payment identity.'));
            }, 5);

            return $attempt->refresh();
        } catch (UniqueConstraintViolationException $exception) {
            $this->journal->markUnknown($operation);
            throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'This canonical Stripe payment was attached concurrently.', previous: $exception);
        } catch (Throwable $exception) {
            $this->journal->markUnknown($operation);
            throw $exception;
        }
    }

    /**
     * Reject missing, substituted, or unsupported canonical payment identities and state.
     *
     * @param  StripePaymentState  $payment
     */
    private function assertIdentity(string $reference, array $payment): void
    {
        if (($payment['paymentIntentId'] === null && $payment['chargeId'] === null)
            || ($payment['paymentIntentId'] !== null && preg_match('/^pi_[A-Za-z0-9]+$/D', $payment['paymentIntentId']) !== 1)
            || ($payment['chargeId'] !== null && preg_match('/^ch_[A-Za-z0-9]+$/D', $payment['chargeId']) !== 1)
            || (str_starts_with($reference, 'pi_') && $payment['paymentIntentId'] !== $reference)
            || ! in_array($payment['status'], ['succeeded', 'requires_capture', 'processing', 'requires_payment_method', 'failed', 'canceled', 'requires_action', 'requires_confirmation'], true)) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Stripe did not resolve a supported canonical payment.');
        }
    }

    /** Validate the configured order and redirect policy before durable writes. */
    private function validate(OrderPaymentSnapshot $order, string $successUrl, string $cancelUrl): void
    {
        if (! config()->boolean('nvl-payments.enabled')) {
            throw PaymentsException::because(PaymentsResponseCode::FeatureDisabled, 'Payments is disabled.');
        }
        if (! $order->payable || ! in_array(strtoupper($order->currency), config()->array('nvl-payments.allowed_currencies'), true)) {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Order is not payable with the configured currency.');
        }
        if (! in_array(config('nvl-payments.checkout.capture_method'), ['automatic', 'manual'], true) || config()->integer('nvl-payments.checkout.expires_in_minutes') < 30 || config()->integer('nvl-payments.checkout.expires_in_minutes') > 1440) {
            throw PaymentsException::because(PaymentsResponseCode::InvalidConfiguration, 'Invalid Checkout capture method or expiration interval.');
        }
        foreach ([$successUrl, $cancelUrl] as $url) {
            $parts = parse_url($url);
            if ($parts === false || filter_var($url, FILTER_VALIDATE_URL) === false || ($parts['scheme'] ?? null) !== 'https' || ! in_array(strtolower($parts['host'] ?? ''), config()->array('nvl-payments.checkout.return_hosts'), true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
                throw new InvalidArgumentException('Checkout return URLs must use an allowed HTTPS host.');
            }
        }
    }

    /**
     * Create remotely only after the owning attempt and operation have committed.
     *
     * @return HostedCheckout
     */
    private function create(PaymentAttempt $attempt, OrderPaymentSnapshot $order, string $successUrl, string $cancelUrl): array
    {
        $operation = PaymentOperation::query()->findOrFail($attempt->id);
        try {
            $checkout = $this->gateway->createCheckout($order, $attempt->capture_method ?? 'automatic', $successUrl, $cancelUrl, CarbonImmutable::instance($attempt->expires_at ?? throw new DomainException('Missing checkout expiry.')), $operation->idempotency_key);
            $attempt->getConnection()->transaction(function () use ($attempt, $operation, $checkout): void {
                PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->where('state', 'reserved')->update([
                    'state' => 'open', 'stripe_checkout_session_id' => $checkout['sessionId'],
                    'checkout_url' => $checkout['url'], 'expires_at' => $checkout['expiresAt'],
                ]);
                $this->journal->complete($operation, $checkout['sessionId']);
            }, 5);

            return $checkout;
        } catch (Throwable $exception) {
            $this->journal->markUnknown($operation);
            throw $exception;
        }
    }

    /**
     * Claim the open or failed attempt before querying or expiring its remote Session.
     *
     * @return HostedCheckout|null
     */
    private function reuseOrExpire(PaymentAttempt $attempt, OrderPaymentSnapshot $order, string $actorId): ?array
    {
        $recoveringFailure = $attempt->state === 'failed';
        if (! in_array($attempt->state, ['open', 'failed'], true) || $attempt->stripe_checkout_session_id === null || PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->where('state', $attempt->state)->update(['state' => 'checking']) !== 1) {
            throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Checkout is unresolved or already paying; reconcile before retrying.');
        }
        $attempt->refresh();
        $operation = null;
        try {
            $remote = $this->gateway->checkout($attempt->stripe_checkout_session_id ?? throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Checkout identity changed before verification.'));
            $this->verifySession($attempt, $remote);
            $changed = $attempt->order_revision !== $order->revision || $attempt->amount_minor !== $order->amountMinor || $attempt->currency !== strtoupper($order->currency);
            if ($remote['paymentStatus'] !== 'unpaid' || ($remote['status'] === 'complete' && ! $recoveringFailure)) {
                PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->where('state', 'checking')->update(['state' => $changed ? 'payment_exception' : 'processing']);
                throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'The existing Session may have received payment.');
            }
            if ($remote['status'] === 'open' && ! $changed && ! $recoveringFailure && $remote['expiresAt']->isFuture()) {
                if (PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->where('state', 'checking')->update(['state' => 'open']) !== 1) {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Payment state changed while checking its Session.');
                }

                return ['sessionId' => $remote['sessionId'], 'url' => $attempt->checkout_url ?? throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Hosted URL requires reconciliation.'), 'expiresAt' => $remote['expiresAt']];
            }
            if ($remote['status'] === 'open') {
                $operation = $attempt->getConnection()->transaction(function () use ($attempt, $actorId): PaymentOperation {
                    if (PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->where('state', 'checking')->update(['state' => 'expiring']) !== 1) {
                        throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Payment state changed before Session expiry.');
                    }
                    $attempt->refresh();
                    $existing = PaymentOperation::query()->where('payment_attempt_id', $attempt->id)->where('type', 'expire_checkout')->lockForUpdate()->first();
                    if ($existing !== null) {
                        return $existing;
                    }
                    $operation = $this->journal->reserve('expire_checkout', $attempt->order_reference, (string) Str::uuid(), $actorId, $attempt->stripe_checkout_session_id ?? '', null);
                    $operation->update(['payment_attempt_id' => $attempt->id]);

                    return $operation;
                }, 5);
                $this->gateway->expireCheckout($remote['sessionId'], $operation->idempotency_key);
                $remote = $this->gateway->checkout($remote['sessionId']);
                $this->verifySession($attempt, $remote);
            }
            if ((! ($recoveringFailure && $remote['status'] === 'complete') && $remote['status'] !== 'expired') || $remote['paymentStatus'] !== 'unpaid') {
                if ($remote['paymentStatus'] !== 'unpaid' || $remote['status'] === 'complete') {
                    PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->whereIn('state', ['checking', 'expiring'])->update(['state' => $changed ? 'payment_exception' : 'processing']);
                }
                throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Session expiry was not confirmed.');
            }
            $this->assertCannotCollect($attempt, $remote);
            $attempt->getConnection()->transaction(function () use ($attempt, $operation, $remote): void {
                $current = PaymentsModelRegistry::attemptQuery()->lockForUpdate()->findOrFail($attempt->id);
                if ($current->getRawOriginal() !== $attempt->getRawOriginal()) {
                    throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'Payment changed during recovery; reconcile before retrying.');
                }
                if (PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->whereIn('state', ['checking', 'expiring'])->update(['state' => 'expired', 'reservation_key' => null, 'last_synced_at' => now()]) !== 1) {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Payment state changed before reservation release.');
                }
                if ($operation !== null) {
                    $this->journal->complete($operation, $remote['sessionId']);
                }
            }, 5);

            return null;
        } catch (Throwable $exception) {
            if ($operation !== null) {
                $this->journal->markUnknown($operation);
            }
            PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->whereIn('state', ['checking', 'expiring'])->update(['state' => 'unknown']);
            throw $exception;
        }
    }

    /**
     * Require a closed Checkout flow and fresh non-collectible financial facts before release.
     *
     * @param  StripeCheckoutState  $remote
     */
    private function assertCannotCollect(PaymentAttempt $attempt, array $remote): void
    {
        if ($remote['paymentIntentId'] === null) {
            if ($remote['status'] !== 'expired') {
                throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'A completed failed Checkout requires its PaymentIntent identity.');
            }

            return;
        }
        $payment = $this->gateway->payment($remote['paymentIntentId']);
        $this->syncer->assertMatches($attempt, $payment);
        if ($payment['paymentIntentId'] !== $remote['paymentIntentId'] || ! in_array($payment['status'], ['requires_payment_method', 'canceled'], true)
            || $payment['capturedAmountMinor'] !== 0 || $payment['refundedAmountMinor'] !== 0 || $payment['amountCapturableMinor'] !== 0) {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'The previous Checkout can still collect money.');
        }
    }

    /**
     * Reject remote facts that do not match the reserved payment identity.
     *
     * @param  StripeCheckoutState  $remote
     */
    private function verifySession(PaymentAttempt $attempt, array $remote): void
    {
        if ($remote['sessionId'] !== $attempt->stripe_checkout_session_id || $remote['accountId'] !== config('nvl-payments.stripe.account_id') || $remote['livemode'] !== config('nvl-payments.stripe.livemode') || ($attempt->stripe_payment_intent_id !== null && $remote['paymentIntentId'] !== $attempt->stripe_payment_intent_id) || $remote['orderReference'] !== $attempt->order_reference || $remote['orderRevision'] !== $attempt->order_revision || $remote['amountMinor'] !== $attempt->amount_minor || strtoupper($remote['currency']) !== $attempt->currency) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe Session does not match its reserved order payment.');
        }
    }
}
