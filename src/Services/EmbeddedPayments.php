<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Payments\Contracts\PaymentIntentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Exceptions\ProviderRefused;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Throwable;

/**
 * Host-authorized Payment Element lifecycle for an immutable order snapshot.
 *
 * @api
 *
 * @phpstan-import-type StripeIntentState from PaymentIntentGateway
 *
 * @phpstan-type EmbeddedCheckout array{intentId:string,clientSecret:string,publishableKey:string,status:string,expiresAt:CarbonImmutable}
 */
class EmbeddedPayments
{
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly PaymentIntentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentOrderOwnership $scope,
        private readonly PaymentStateSyncer $syncer,
        private readonly PaymentReconciliation $reconciliation,
        private readonly ?PaymentCustomers $customers = null,
    ) {}

    /**
     * Reserve and start one PaymentIntent per globally unique host attempt UUID.
     *
     * @return EmbeddedCheckout
     */
    public function start(string $orderReference, string $attemptReference, Authenticatable $actor, ?string $hostCustomerReference = null): array
    {
        PaymentsAvailability::assertOutsideTransaction();
        $order = $this->orders->resolve($orderReference);
        $actor = $this->authorize($order, $orderReference, 'start_embedded', $actor);
        $actorId = $actor->getAuthIdentifier();
        if (! Str::isUuid($attemptReference) || (! is_string($actorId) && ! is_int($actorId)) || trim((string) $actorId) === '') {
            throw new InvalidArgumentException('An attempt UUID and a stable actor identifier are required.');
        }
        $attemptReference = strtolower($attemptReference);
        $key = config('nvl-payments.stripe.publishable_key');
        $prefix = config()->boolean('nvl-payments.stripe.livemode') ? 'pk_live_' : 'pk_test_';
        if (! is_string($key) || ! str_starts_with($key, $prefix) || strlen($key) === strlen($prefix)) {
            throw PaymentsException::because(PaymentsResponseCode::InvalidConfiguration, 'Configure a publishable Stripe key for this mode.');
        }

        $attempt = PaymentsModelRegistry::attemptQuery()->find($attemptReference);
        if ($attempt === null) {
            [$capture, $methods, $expiry] = $this->configuration($order);
            $customer = $hostCustomerReference === null ? null : ($this->customers ?? app(PaymentCustomers::class))->resolve($hostCustomerReference);
            $connection = PaymentsModelRegistry::newAttempt()->getConnection();
            try {
                $attempt = $connection->transaction(function () use ($order, $attemptReference, $actorId, $capture, $methods, $expiry, $customer, $hostCustomerReference): PaymentAttempt {
                    $operation = $this->reserve($order, $attemptReference, (string) $actorId, $hostCustomerReference);
                    $reservation = $this->scope->reservationKey($order->reference);
                    PaymentOperation::query()->whereKey($operation->id)->update(['id' => $operation->id]);
                    $attempt = PaymentsModelRegistry::attemptQuery()->find($attemptReference);
                    if ($attempt === null) {
                        $attempt = PaymentsModelRegistry::newAttempt([
                            'order_reference' => $order->reference,
                            'order_revision' => $order->revision,
                            'amount_minor' => $order->amountMinor,
                            'currency' => strtoupper($order->currency),
                            'origin' => 'embedded',
                            'state' => 'reserved',
                            'reservation_key' => $reservation,
                            'capture_method' => $capture,
                            'embedded_methods' => $methods,
                            'embedded_customer' => $customer,
                            'stripe_account_id' => config('nvl-payments.stripe.account_id'),
                            'stripe_livemode' => config('nvl-payments.stripe.livemode'),
                            'expires_at' => CarbonImmutable::now('UTC')->startOfSecond()->addMinutes($expiry),
                        ]);
                        $attempt->forceFill(['id' => $attemptReference])->save();
                    }
                    if (PaymentsModelRegistry::attemptQuery()->where('order_reference', $order->reference)->whereKeyNot($attempt->id)->whereIn('state', ['processing', 'authorized', 'captured', 'partially_refunded', 'refunded', 'payment_exception'])->exists()) {
                        throw PaymentsException::because(PaymentsResponseCode::CheckoutConflict, 'An existing payment blocks another checkout.');
                    }
                    $operation->update(['payment_attempt_id' => $attempt->id]);

                    return $attempt;
                }, 5);
            } catch (UniqueConstraintViolationException $failure) {
                throw PaymentsException::because(PaymentsResponseCode::CheckoutConflict, 'The order already has an active checkout.', previous: $failure);
            }
        }
        if ($attempt->origin !== 'embedded' || $attempt->order_reference !== $orderReference) {
            throw PaymentsException::because(PaymentsResponseCode::CheckoutConflict, 'This attempt reference is already in use.');
        }
        if ($attempt->stripe_account_id !== config('nvl-payments.stripe.account_id') || $attempt->stripe_livemode !== config('nvl-payments.stripe.livemode')) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Embedded payment belongs to another Stripe account or mode.');
        }
        $operation = $this->reserve($order, $attemptReference, (string) $actorId, $hostCustomerReference);
        $intentId = $attempt->stripe_payment_intent_id;
        $expiry = $attempt->expires_at?->toImmutable() ?? throw new \LogicException('Embedded attempt has no expiry.');
        if ($intentId === null && ($expiry->isPast() || $operation->status === 'failed')) {
            throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'An unresolved or refused attempt cannot create another intent.');
        }
        if ($intentId !== null && $expiry->isPast()) {
            $attempt = $this->reconciliation->reconcile($attempt);
            if (! in_array($attempt->state, ['captured', 'partially_refunded', 'refunded'], true)) {
                throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'The embedded payment window has expired.');
            }
        }

        try {
            $remote = $intentId === null
                ? $this->gateway->createIntent($order, $attempt->capture_method ?? 'automatic_async', $attempt->embedded_methods ?? [], $attempt->embedded_customer, $operation->idempotency_key)
                : $this->gateway->intent($intentId);
            if ($intentId !== null && $remote['paymentIntentId'] !== $intentId) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe returned a different intent.');
            }
            $this->link($attempt, $operation, $remote);
        } catch (Throwable $failure) {
            if ($intentId === null && $failure instanceof ProviderRefused && $failure->stripeCode !== 'idempotency_key_in_use' && $operation->status === 'reserved') {
                $attempt->getConnection()->transaction(function () use ($operation, $attempt): void {
                    if (PaymentOperation::query()->whereKey($operation->id)->where('status', 'reserved')->update(['status' => 'failed', 'resolved_at' => now()]) === 1) {
                        PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->whereNull('stripe_payment_intent_id')->update(['state' => 'failed', 'reservation_key' => null]);
                    }
                }, 5);
            } else {
                $this->journal->markUnknown($operation);
            }

            throw $failure;
        }

        return [
            'intentId' => $remote['paymentIntentId'] ?? throw new \LogicException('Linked intent has no Stripe ID.'),
            'clientSecret' => $remote['clientSecret'] ?? throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Stripe returned no client secret.'),
            'publishableKey' => $key,
            'status' => $attempt->refresh()->state === 'payment_exception' ? 'payment_exception' : $remote['status'],
            'expiresAt' => $expiry,
        ];
    }

    /** Read authoritative payment and refund facts after host authorization. */
    public function settle(string $attemptReference, Authenticatable $actor): PaymentAttempt
    {
        return $this->reconciliation->reconcile($this->authorizedAttempt($attemptReference, $actor, 'settle_embedded'));
    }

    /** Returns true only when Stripe confirms cancellation and the reservation is released. */
    public function close(string $attemptReference, Authenticatable $actor): bool
    {
        PaymentsAvailability::assertOutsideTransaction();
        $attempt = $this->authorizedAttempt($attemptReference, $actor, 'close_embedded');
        if ($attempt->stripe_payment_intent_id === null) {
            return false;
        }
        $result = $this->gateway->cancel($attempt->stripe_payment_intent_id, 'payments:close:'.$attempt->id);
        if ($result['paymentIntentId'] !== $attempt->stripe_payment_intent_id) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe returned a different intent.');
        }
        $this->syncer->sync($result);

        return $result['status'] === 'canceled' && $attempt->refresh()->state === 'canceled';
    }

    /** Recover a remote intent whose exact operation metadata proves it belongs to this attempt. */
    public function recover(string $attemptReference, string $intentId, Authenticatable $actor): PaymentAttempt
    {
        PaymentsAvailability::assertOutsideTransaction();
        $attempt = $this->authorizedAttempt($attemptReference, $actor, 'recover_embedded');
        $operation = PaymentOperation::query()->whereKey($attempt->id)->where('type', 'embedded')->sole();
        $remote = $this->gateway->intent($intentId);
        if ($remote['paymentIntentId'] !== $intentId) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe returned a different intent.');
        }
        $this->link($attempt, $operation, $remote);

        return $attempt->refresh();
    }

    private function authorizedAttempt(string $reference, Authenticatable $actor, string $ability): PaymentAttempt
    {
        $attempt = PaymentsModelRegistry::findAttemptOrFail($reference);
        if ($attempt->origin !== 'embedded') {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Expected an embedded payment.');
        }
        $this->authorize($this->orders->resolve($attempt->order_reference), $attempt->order_reference, $ability, $actor);

        return $attempt;
    }

    private function authorize(OrderPaymentSnapshot $order, string $reference, string $ability, Authenticatable $actor): Authenticatable
    {
        if (! config()->boolean('nvl-payments.enabled')) {
            throw PaymentsException::because(PaymentsResponseCode::FeatureDisabled, 'Payments is disabled.');
        }
        if ($order->reference !== $reference) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Order provider returned a different order.');
        }
        $actor = $this->scope->resolveActor($order, $actor);
        $this->access->assertCanManage($actor, $ability, $order);

        return $actor;
    }

    /** @return array{string,list<string>,int} */
    private function configuration(OrderPaymentSnapshot $order): array
    {
        $currency = strtoupper($order->currency);
        $minimum = config('nvl-payments.minimum_amounts.'.$currency, 1);
        if (! $order->payable || ! in_array($currency, config()->array('nvl-payments.allowed_currencies'), true)
            || ! is_int($minimum) || $minimum < 1 || $order->amountMinor < $minimum) {
            throw PaymentsException::because(PaymentsResponseCode::PaymentStateInvalid, 'Order is not payable for the configured currency and minimum.');
        }
        $capture = config()->string('nvl-payments.embedded.capture_method');
        $methods = config()->array('nvl-payments.embedded.allowed_payment_method_types');
        $expiry = config()->integer('nvl-payments.embedded.expires_in_minutes');
        if (! in_array($capture, ['automatic', 'automatic_async', 'manual'], true) || ! array_is_list($methods)
            || $methods === [] || array_filter($methods, fn ($method): bool => is_string($method) && preg_match('/^[a-z][a-z0-9_]*$/D', $method) === 1) !== $methods
            || ($capture === 'manual' && $methods !== ['card']) || $expiry < 1 || $expiry > 1380) {
            throw PaymentsException::because(PaymentsResponseCode::InvalidConfiguration, 'Invalid embedded capture, methods or expiry configuration.');
        }

        return [$capture, $methods, $expiry];
    }

    private function reserve(OrderPaymentSnapshot $order, string $attempt, string $actor, ?string $customerReference): PaymentOperation
    {
        $fingerprint = hash('sha256', json_encode([$order->revision, strtoupper($order->currency), $customerReference], JSON_THROW_ON_ERROR));

        return $this->journal->reserve('embedded', $order->reference, $attempt, $actor, $fingerprint, $order->amountMinor);
    }

    /** @param StripeIntentState $remote */
    private function link(PaymentAttempt $attempt, PaymentOperation $operation, array $remote): void
    {
        $intent = $remote['paymentIntentId'];
        if ($intent === null || ! str_starts_with($intent, 'pi_') || ($remote['operationKey'] ?? null) !== $operation->idempotency_key) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe intent lacks this attempt operation proof.');
        }
        $attempt->getConnection()->transaction(function () use ($attempt, $operation, $remote, $intent): void {
            $current = PaymentsModelRegistry::attemptQuery()->lockForUpdate()->findOrFail($attempt->id);
            $this->syncer->assertMatches($current, $remote);
            if ($current->stripe_payment_intent_id === null) {
                $current->update(['stripe_payment_intent_id' => $intent]);
            }
            $this->journal->complete($operation, $intent);
            $this->syncer->sync($remote);
        }, 5);
    }
}
