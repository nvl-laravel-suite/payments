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
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\PaymentOperationJournal;
use Nvl\Payments\Services\PaymentStateSyncer;
use Nvl\Payments\ValueObjects\HostedCheckout;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Throwable;

/**
 * Reserves one hosted checkout for a trusted host order reference.
 * Local transactions bracket remote calls; the explicit primitive signature is the host contract.
 */
final class StartCheckoutAction
{
    /** Inject host authorization, trusted facts, and the durable Stripe boundary. */
    public function __construct(
        private readonly PaymentOrderProvider $orders,
        private readonly PaymentManagementAccess $access,
        private readonly PaymentGateway $gateway,
        private readonly PaymentOperationJournal $journal,
        private readonly PaymentStateSyncer $syncer,
    ) {}

    /** Return an existing safe URL or reserve and create a new Checkout Session. */
    public function execute(string $orderReference, Authenticatable $actor, string $successUrl, string $cancelUrl): HostedCheckout
    {
        $order = $this->orders->resolve($orderReference);
        $this->access->assertCanManage($actor, 'start_checkout', $order);
        $this->validate($order, $successUrl, $cancelUrl);
        $connection = (new PaymentAttempt)->getConnection();
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
                $attempt = PaymentAttempt::query()->createOrFirst(['reservation_key' => hash('sha256', $order->reference)], [
                    'order_reference' => $order->reference, 'order_revision' => $order->revision,
                    'amount_minor' => $order->amountMinor, 'currency' => strtoupper($order->currency),
                    'origin' => 'checkout', 'state' => 'reserved',
                    'capture_method' => config('payments.checkout.capture_method', 'automatic'),
                    'expires_at' => CarbonImmutable::now()->addMinutes(config()->integer('payments.checkout.expires_in_minutes')),
                ]);
                if (PaymentAttempt::query()->where('order_reference', $order->reference)->whereKeyNot($attempt->id)->whereIn('state', ['processing', 'authorized', 'captured', 'partially_refunded', 'refunded', 'payment_exception'])->exists()) {
                    throw new DomainException('An existing payment blocks another checkout.');
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
        throw new DomainException('Checkout reservation changed concurrently; retry after reconciliation.');
    }

    /** Validate the configured order and redirect policy before durable writes. */
    private function validate(OrderPaymentSnapshot $order, string $successUrl, string $cancelUrl): void
    {
        if (! config()->boolean('payments.enabled') || ! $order->payable || ! in_array(strtoupper($order->currency), config()->array('payments.allowed_currencies'), true)) {
            throw new DomainException('Order is not payable with the configured currency.');
        }
        if (! in_array(config('payments.checkout.capture_method'), ['automatic', 'manual'], true) || config()->integer('payments.checkout.expires_in_minutes') < 30 || config()->integer('payments.checkout.expires_in_minutes') > 1440) {
            throw new InvalidArgumentException('Invalid Checkout capture method or expiration interval.');
        }
        foreach ([$successUrl, $cancelUrl] as $url) {
            $parts = parse_url($url);
            if ($parts === false || filter_var($url, FILTER_VALIDATE_URL) === false || ($parts['scheme'] ?? null) !== 'https' || ! in_array(strtolower($parts['host'] ?? ''), config()->array('payments.checkout.return_hosts'), true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
                throw new InvalidArgumentException('Checkout return URLs must use an allowed HTTPS host.');
            }
        }
    }

    /** Create remotely only after the owning attempt and operation have committed. */
    private function create(PaymentAttempt $attempt, OrderPaymentSnapshot $order, string $successUrl, string $cancelUrl): HostedCheckout
    {
        $operation = PaymentOperation::query()->findOrFail($attempt->id);
        try {
            $checkout = $this->gateway->createCheckout($order, $attempt->capture_method ?? 'automatic', $successUrl, $cancelUrl, CarbonImmutable::instance($attempt->expires_at ?? throw new DomainException('Missing checkout expiry.')), $operation->idempotency_key);
            $attempt->getConnection()->transaction(function () use ($attempt, $operation, $checkout): void {
                PaymentAttempt::query()->whereKey($attempt->id)->where('state', 'reserved')->update([
                    'state' => 'open', 'stripe_checkout_session_id' => $checkout->sessionId,
                    'checkout_url' => $checkout->url, 'expires_at' => $checkout->expiresAt,
                ]);
                $this->journal->complete($operation, $checkout->sessionId);
            }, 5);

            return $checkout;
        } catch (Throwable $exception) {
            $this->journal->markUnknown($operation);
            throw $exception;
        }
    }

    /** Claim the open or failed attempt before querying or expiring its remote Session. */
    private function reuseOrExpire(PaymentAttempt $attempt, OrderPaymentSnapshot $order, string $actorId): ?HostedCheckout
    {
        $recoveringFailure = $attempt->state === 'failed';
        if (! in_array($attempt->state, ['open', 'failed'], true) || $attempt->stripe_checkout_session_id === null || PaymentAttempt::query()->whereKey($attempt->id)->where('state', $attempt->state)->update(['state' => 'checking']) !== 1) {
            throw new DomainException('Checkout is unresolved or already paying; reconcile before retrying.');
        }
        $attempt->refresh();
        $operation = null;
        try {
            $remote = $this->gateway->checkout($attempt->stripe_checkout_session_id ?? throw new DomainException('Checkout identity changed before verification.'));
            $this->verifySession($attempt, $remote);
            $changed = $attempt->order_revision !== $order->revision || $attempt->amount_minor !== $order->amountMinor || $attempt->currency !== strtoupper($order->currency);
            if ($remote->paymentStatus !== 'unpaid' || ($remote->status === 'complete' && ! $recoveringFailure)) {
                PaymentAttempt::query()->whereKey($attempt->id)->where('state', 'checking')->update(['state' => $changed ? 'payment_exception' : 'processing']);
                throw new DomainException('The existing Session may have received payment.');
            }
            if ($remote->status === 'open' && ! $changed && ! $recoveringFailure && $remote->expiresAt->isFuture()) {
                if (PaymentAttempt::query()->whereKey($attempt->id)->where('state', 'checking')->update(['state' => 'open']) !== 1) {
                    throw new DomainException('Payment state changed while checking its Session.');
                }

                return new HostedCheckout($remote->sessionId, $attempt->checkout_url ?? throw new DomainException('Hosted URL requires reconciliation.'), $remote->expiresAt);
            }
            if ($remote->status === 'open') {
                $operation = $attempt->getConnection()->transaction(function () use ($attempt, $actorId): PaymentOperation {
                    if (PaymentAttempt::query()->whereKey($attempt->id)->where('state', 'checking')->update(['state' => 'expiring']) !== 1) {
                        throw new DomainException('Payment state changed before Session expiry.');
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
                $this->gateway->expireCheckout($remote->sessionId, $operation->idempotency_key);
                $remote = $this->gateway->checkout($remote->sessionId);
                $this->verifySession($attempt, $remote);
            }
            if ((! ($recoveringFailure && $remote->status === 'complete') && $remote->status !== 'expired') || $remote->paymentStatus !== 'unpaid') {
                if ($remote->paymentStatus !== 'unpaid' || $remote->status === 'complete') {
                    PaymentAttempt::query()->whereKey($attempt->id)->whereIn('state', ['checking', 'expiring'])->update(['state' => $changed ? 'payment_exception' : 'processing']);
                }
                throw new DomainException('Session expiry was not confirmed.');
            }
            $this->assertCannotCollect($attempt, $remote);
            $attempt->getConnection()->transaction(function () use ($attempt, $operation, $remote): void {
                $current = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($current->getRawOriginal() !== $attempt->getRawOriginal()) {
                    throw new DomainException('Payment changed during recovery; reconcile before retrying.');
                }
                if (PaymentAttempt::query()->whereKey($attempt->id)->whereIn('state', ['checking', 'expiring'])->update(['state' => 'expired', 'reservation_key' => null, 'last_synced_at' => now()]) !== 1) {
                    throw new DomainException('Payment state changed before reservation release.');
                }
                if ($operation !== null) {
                    $this->journal->complete($operation, $remote->sessionId);
                }
            }, 5);

            return null;
        } catch (Throwable $exception) {
            if ($operation !== null) {
                $this->journal->markUnknown($operation);
            }
            PaymentAttempt::query()->whereKey($attempt->id)->whereIn('state', ['checking', 'expiring'])->update(['state' => 'unknown']);
            throw $exception;
        }
    }

    /** Require a closed Checkout flow and fresh non-collectible financial facts before release. */
    private function assertCannotCollect(PaymentAttempt $attempt, StripeCheckoutState $remote): void
    {
        if ($remote->paymentIntentId === null) {
            if ($remote->status !== 'expired') {
                throw new DomainException('A completed failed Checkout requires its PaymentIntent identity.');
            }

            return;
        }
        $payment = $this->gateway->payment($remote->paymentIntentId);
        $this->syncer->assertMatches($attempt, $payment);
        if ($payment->paymentIntentId !== $remote->paymentIntentId || ! in_array($payment->status, ['requires_payment_method', 'canceled'], true)
            || $payment->capturedAmountMinor !== 0 || $payment->refundedAmountMinor !== 0 || $payment->amountCapturableMinor !== 0) {
            throw new DomainException('The previous Checkout can still collect money.');
        }
    }

    /** Reject remote facts that do not match the reserved payment identity. */
    private function verifySession(PaymentAttempt $attempt, StripeCheckoutState $remote): void
    {
        if ($remote->sessionId !== $attempt->stripe_checkout_session_id || $remote->accountId !== config('payments.stripe.account_id') || $remote->livemode !== config('payments.stripe.livemode') || ($attempt->stripe_payment_intent_id !== null && $remote->paymentIntentId !== $attempt->stripe_payment_intent_id) || $remote->orderReference !== $attempt->order_reference || $remote->orderRevision !== $attempt->order_revision || $remote->amountMinor !== $attempt->amount_minor || strtoupper($remote->currency) !== $attempt->currency) {
            throw new DomainException('Stripe Session does not match its reserved order payment.');
        }
    }
}
