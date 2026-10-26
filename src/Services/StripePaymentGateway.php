<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\StripeObject;
use UnexpectedValueException;

/**
 * Stripe SDK adapter with credential identity and remote mode checks.
 *
 * @phpstan-import-type HostedCheckout from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripeCheckoutState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripeRefundState from \Nvl\Payments\Contracts\PaymentGateway
 */
final class StripePaymentGateway implements PaymentGateway
{
    /** @param list<string> $paymentMethodTypes Explicit Checkout methods; manual capture is limited to cards. */
    public function __construct(
        private readonly StripeClient $client,
        private readonly string $accountId,
        private readonly bool $livemode,
        private readonly array $paymentMethodTypes = ['card'],
    ) {}

    /**
     * @return HostedCheckout
     */
    public function createCheckout(OrderPaymentSnapshot $order, string $captureMethod, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $idempotencyKey): array
    {
        if (! in_array($captureMethod, ['automatic', 'manual'], true) || $this->paymentMethodTypes === [] || ($captureMethod === 'manual' && array_diff($this->paymentMethodTypes, ['card']) !== [])) {
            throw new InvalidArgumentException('Unsupported capture method or payment method combination.');
        }
        $options = $this->options($idempotencyKey);
        $this->verifyAccount();
        $metadata = ['order_reference' => $order->reference, 'order_revision' => $order->revision, 'payment_operation_key' => $idempotencyKey];
        $params = [
            'mode' => 'payment', 'payment_method_types' => $this->paymentMethodTypes,
            'success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'expires_at' => $expiresAt->getTimestamp(),
            'client_reference_id' => $order->reference, 'metadata' => $metadata,
            'payment_intent_data' => ['capture_method' => $captureMethod, 'metadata' => $metadata],
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($order->currency), 'unit_amount' => $order->amountMinor, 'product_data' => ['name' => $order->label]]]],
        ];
        if ($order->email !== null) {
            $params['customer_email'] = $order->email;
        }
        $session = $this->client->checkout->sessions->create($params, $options);
        $this->verifyMode($session);

        return ['sessionId' => $session->id, 'url' => $session->url ?? throw new UnexpectedValueException('Stripe returned no hosted URL.'), 'expiresAt' => CarbonImmutable::createFromTimestampUTC($session->expires_at)];
    }

    public function expireCheckout(string $sessionId, string $idempotencyKey): void
    {
        $options = $this->options($idempotencyKey);
        $this->checkout($sessionId);
        $this->verifyMode($this->client->checkout->sessions->expire($sessionId, [], $options));
    }

    /**
     * @return StripeCheckoutState
     */
    public function checkout(string $sessionId): array
    {
        $this->verifyAccount();
        $session = $this->client->checkout->sessions->retrieve($sessionId);
        $this->verifyMode($session);
        if ($session->mode !== 'payment') {
            throw new UnexpectedValueException('Checkout Session is not a one-time payment.');
        }

        return ['sessionId' => $session->id, 'paymentIntentId' => $this->reference($session->payment_intent), 'accountId' => $this->accountId, 'livemode' => $session->livemode, 'amountMinor' => $session->amount_total ?? 0, 'currency' => $session->currency ?? '', 'status' => $session->status ?? throw new UnexpectedValueException('Missing Checkout status.'), 'paymentStatus' => $session->payment_status, 'orderReference' => $this->metadataString($session->metadata, 'order_reference'), 'orderRevision' => $this->metadataString($session->metadata, 'order_revision'), 'expiresAt' => CarbonImmutable::createFromTimestampUTC($session->expires_at), 'operationKey' => $this->metadataString($session->metadata, 'payment_operation_key'), 'url' => $session->url];
    }

    /**
     * @return StripePaymentState
     */
    public function payment(string $paymentReference): array
    {
        $field = $this->paymentField($paymentReference);
        $this->verifyAccount();
        if ($field === 'payment_intent') {
            return $this->intentState($this->client->paymentIntents->retrieve($paymentReference, ['expand' => ['latest_charge']]));
        }
        $charge = $this->client->charges->retrieve($paymentReference);
        $this->verifyMode($charge);
        if ($charge->payment_intent !== null) {
            return $this->payment($this->reference($charge->payment_intent) ?? throw new UnexpectedValueException('Missing PaymentIntent ID.'));
        }

        return ['paymentIntentId' => null, 'chargeId' => $charge->id, 'accountId' => $this->accountId, 'livemode' => $charge->livemode, 'amountMinor' => $charge->amount, 'capturedAmountMinor' => $charge->amount_captured, 'refundedAmountMinor' => $charge->amount_refunded, 'currency' => $charge->currency, 'status' => $charge->captured ? $charge->status : ($charge->status === 'succeeded' ? 'requires_capture' : $charge->status), 'captureMethod' => $charge->captured ? 'automatic' : 'manual', 'amountCapturableMinor' => null, 'paymentMethodType' => null, 'partialAuthorizationStatus' => null];
    }

    /**
     * @return StripePaymentState
     */
    public function resolveExisting(string $reference): array
    {
        if (str_starts_with($reference, 'txn_')) {
            $this->verifyAccount();
            $transaction = $this->client->balanceTransactions->retrieve($reference);
            $source = $this->reference($transaction->source);
            if (! in_array($transaction->type, ['charge', 'payment'], true) || $source === null || ! str_starts_with($source, 'ch_')) {
                throw new InvalidArgumentException('Balance transaction does not refer to a payment.');
            }
            $reference = $source;
        }

        return $this->payment($reference);
    }

    /**
     * @return StripePaymentState
     */
    public function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): array
    {
        $this->positiveAmount($amountMinor);
        $options = $this->options($idempotencyKey);
        $this->requireIntent($paymentIntentId);
        $this->payment($paymentIntentId);

        return $this->intentState($this->client->paymentIntents->capture($paymentIntentId, ['amount_to_capture' => $amountMinor, 'final_capture' => true, 'expand' => ['latest_charge']], $options));
    }

    /**
     * @return StripePaymentState
     */
    public function cancel(string $paymentIntentId, string $idempotencyKey): array
    {
        $options = $this->options($idempotencyKey);
        $this->requireIntent($paymentIntentId);
        $this->payment($paymentIntentId);

        return $this->intentState($this->client->paymentIntents->cancel($paymentIntentId, ['expand' => ['latest_charge']], $options));
    }

    /**
     * @return StripeRefundState
     */
    public function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): array
    {
        $this->positiveAmount($amountMinor);
        $this->paymentField($paymentId);
        $options = $this->options($idempotencyKey);
        if (! in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) {
            throw new InvalidArgumentException('Unsupported Stripe refund reason.');
        }
        $payment = $this->payment($paymentId);

        return $this->refundState($this->client->refunds->create(array_merge($this->paymentFilter($paymentId), ['amount' => $amountMinor, 'reason' => $reason, 'metadata' => ['payment_operation_key' => $idempotencyKey]]), $options), $payment['livemode']);
    }

    /**
     * @return list<StripeRefundState>
     */
    public function refunds(string $paymentId): array
    {
        $this->paymentField($paymentId);
        $payment = $this->payment($paymentId);
        $states = [];
        foreach ($this->client->refunds->all(array_merge($this->paymentFilter($paymentId), ['limit' => 100]))->autoPagingIterator() as $refund) {
            $states[] = $this->refundState($refund, $payment['livemode']);
        }

        return $states;
    }

    /** Verify the actual account belonging to the injected credential. */
    private function verifyAccount(): void
    {
        if ($this->accountId === '' || $this->client->accounts->retrieve()->id !== $this->accountId) {
            throw new UnexpectedValueException('Stripe credential account does not match Payments configuration.');
        }
    }

    private function verifyMode(StripeObject $object): void
    {
        if ($object['livemode'] !== $this->livemode) {
            throw new UnexpectedValueException('Stripe object mode does not match Payments configuration.');
        }
    }

    /**
     * @return StripePaymentState
     */
    private function intentState(PaymentIntent $intent): array
    {
        $this->verifyMode($intent);
        $charge = $intent->latest_charge;
        if (is_string($charge)) {
            $charge = $this->client->charges->retrieve($charge);
        }
        if ($charge instanceof Charge) {
            $this->verifyMode($charge);
        }

        $capturable = $intent['amount_capturable'];
        $details = $charge instanceof Charge ? $charge['payment_method_details'] : null;
        $method = $details instanceof StripeObject ? $details['type'] : null;
        $card = $details instanceof StripeObject ? $details['card'] : null;
        $partialAuthorization = $card instanceof StripeObject ? $card['partial_authorization'] : null;
        $partialStatus = $partialAuthorization instanceof StripeObject ? $partialAuthorization['status'] : null;

        return ['paymentIntentId' => $intent->id, 'chargeId' => $this->reference($charge), 'accountId' => $this->accountId, 'livemode' => $intent->livemode, 'amountMinor' => $intent->amount, 'capturedAmountMinor' => $intent->amount_received, 'refundedAmountMinor' => $charge->amount_refunded ?? 0, 'currency' => $intent->currency, 'status' => $intent->status, 'captureMethod' => $intent->capture_method, 'amountCapturableMinor' => is_int($capturable) ? $capturable : null, 'paymentMethodType' => is_string($method) ? $method : null, 'partialAuthorizationStatus' => is_string($partialStatus) ? $partialStatus : null];
    }

    /**
     * Refund objects have no livemode; their parent payment establishes the mode.
     *
     * @return StripeRefundState
     */
    private function refundState(Refund $refund, bool $livemode): array
    {
        return ['refundId' => $refund->id, 'paymentIntentId' => $this->reference($refund->payment_intent), 'chargeId' => $this->reference($refund->charge), 'accountId' => $this->accountId, 'livemode' => $livemode, 'amountMinor' => $refund->amount, 'currency' => $refund->currency, 'status' => $refund->status ?? 'pending', 'reason' => $refund->reason, 'operationKey' => $this->metadataString($refund->metadata ?? null, 'payment_operation_key')];
    }

    /** Read optional order correlation without trusting non-string metadata. */
    private function metadataString(?StripeObject $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array{payment_intent: string}|array{charge: string} */
    private function paymentFilter(string $reference): array
    {
        return $this->paymentField($reference) === 'payment_intent' ? ['payment_intent' => $reference] : ['charge' => $reference];
    }

    private function reference(string|StripeObject|null $value): ?string
    {
        return $value instanceof StripeObject ? $value->id : $value;
    }

    /** @return array{idempotency_key: string} */
    private function options(string $key): array
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('An explicit idempotency key is required.');
        }

        return ['idempotency_key' => $key];
    }

    private function paymentField(string $reference): string
    {
        return match (true) {
            str_starts_with($reference, 'pi_') => 'payment_intent',
            str_starts_with($reference, 'ch_') => 'charge',
            default => throw new InvalidArgumentException('Expected a PaymentIntent or Charge reference.'),
        };
    }

    private function requireIntent(string $reference): void
    {
        if ($this->paymentField($reference) !== 'payment_intent') {
            throw new InvalidArgumentException('This operation requires a PaymentIntent.');
        }
    }

    private function positiveAmount(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive minor units.');
        }
    }
}
