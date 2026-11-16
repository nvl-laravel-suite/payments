<?php

declare(strict_types=1);

namespace Nvl\Payments\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Nvl\Payments\Contracts\PaymentCustomerGateway;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentIntentGateway;
use Nvl\Payments\Contracts\PaymentRefundLookup;
use Nvl\Payments\Exceptions\ProviderRefused;
use Nvl\Payments\Services\PaymentWebhooks;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Testing\FakeCalls;
use TypeError;

/**
 * Supplies explicit one-time payment responses without Stripe, storage, or lifecycle execution.
 *
 * @api
 *
 * @phpstan-import-type HostedCheckout from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripeCheckoutState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripeIntentState from \Nvl\Payments\Contracts\PaymentIntentGateway
 * @phpstan-import-type StripeRefundState from \Nvl\Payments\Contracts\PaymentGateway
 */
class FakePaymentGateway implements PaymentCustomerGateway, PaymentIntentGateway, PaymentRefundLookup
{
    use FakeCalls;

    /**
     * Install a fresh gateway instance in the supplied container.
     */
    public static function fake(Container $container): self
    {
        $fake = new self;
        $container->instance(PaymentGateway::class, $fake);

        return $fake;
    }

    /**
     * Return the next scripted Checkout session for the exact order and operation parameters.
     *
     * @return HostedCheckout
     */
    public function createCheckout(OrderPaymentSnapshot $order, string $captureMethod, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $idempotencyKey): array
    {
        $result = $this->invoke('createCheckout', [
            'order' => $order,
            'captureMethod' => $captureMethod,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'expiresAt' => $expiresAt,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->hostedCheckout($result);
    }

    /**
     * Consume the next expiration script while retaining the native void result.
     */
    public function expireCheckout(string $sessionId, string $idempotencyKey): void
    {
        $this->invoke('expireCheckout', ['sessionId' => $sessionId, 'idempotency_key' => $idempotencyKey]);
    }

    /**
     * Return the next scripted Checkout state without retrieving a remote session.
     *
     * @return StripeCheckoutState
     */
    public function checkout(string $sessionId): array
    {
        $result = $this->invoke('checkout', ['sessionId' => $sessionId]);

        return $this->checkoutState($result);
    }

    /**
     * Return the next scripted payment state for the supplied reference.
     *
     * @return StripePaymentState
     */
    public function payment(string $paymentReference): array
    {
        $result = $this->invoke('payment', ['paymentReference' => $paymentReference]);

        return $this->paymentState($result);
    }

    /**
     * Return the next scripted existing-payment resolution without another gateway call.
     *
     * @return StripePaymentState
     */
    public function resolveExisting(string $reference): array
    {
        $result = $this->invoke('resolveExisting', ['reference' => $reference]);

        return $this->paymentState($result);
    }

    /**
     * Return the next scripted capture state for the exact minor amount and idempotency key.
     *
     * @return StripePaymentState
     */
    public function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): array
    {
        $result = $this->invoke('capture', [
            'paymentIntentId' => $paymentIntentId,
            'amountMinor' => $amountMinor,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->paymentState($result);
    }

    /**
     * Return the next scripted cancellation state without mutating authorization evidence.
     *
     * @return StripePaymentState
     */
    public function cancel(string $paymentIntentId, string $idempotencyKey): array
    {
        try {
            $result = $this->invoke('cancel', [
                'paymentIntentId' => $paymentIntentId,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (ProviderRefused $failure) {
            if ($failure->stripeCode === 'payment_intent_unexpected_state') {
                return $this->payment($paymentIntentId);
            }

            throw $failure;
        }

        return $this->paymentState($result);
    }

    /**
     * Return the next scripted refund state for the exact requested refund parameters.
     *
     * @return StripeRefundState
     */
    public function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): array
    {
        $result = $this->invoke('refund', [
            'paymentId' => $paymentId,
            'amountMinor' => $amountMinor,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->refundState($result);
    }

    /**
     * Return the next scripted refund list without retrieving remote refunds.
     *
     * @return list<StripeRefundState>
     */
    public function refunds(string $paymentId): array
    {
        $result = $this->invoke('refunds', ['paymentId' => $paymentId]);

        if (! is_array($result) || ! array_is_list($result)) {
            throw new TypeError('FakePaymentGateway::refunds requires a scripted list of refund facts.');
        }

        $refunds = [];
        foreach ($result as $refund) {
            if (! is_array($refund)) {
                throw new TypeError('FakePaymentGateway::refunds requires a scripted list of refund facts.');
            }

            $refunds[] = $this->refundState($refund);
        }

        return $refunds;
    }

    /** @return StripeIntentState */
    public function createIntent(OrderPaymentSnapshot $order, string $captureMethod, array $allowedMethods, ?string $customerId, string $idempotencyKey): array
    {
        return $this->intentState($this->invoke('createIntent', [
            'order' => $order, 'captureMethod' => $captureMethod, 'allowedMethods' => $allowedMethods,
            'customerId' => $customerId, 'idempotency_key' => $idempotencyKey,
        ]));
    }

    /** @return StripeIntentState */
    public function intent(string $intentId): array
    {
        return $this->intentState($this->invoke('intent', ['intentId' => $intentId]));
    }

    public function createCustomer(string $idempotencyKey): string
    {
        $id = $this->invoke('createCustomer', ['idempotency_key' => $idempotencyKey]);
        if (! is_string($id)) {
            throw new TypeError('FakePaymentGateway requires a scripted Stripe customer ID.');
        }

        return $id;
    }

    /** @return StripeRefundState */
    public function readRefund(string $refundId): array
    {
        return $this->refundState($this->invoke('readRefund', ['refundId' => $refundId]));
    }

    /**
     * Deliver a fixture through the real signed inbox. Omit the secret to exercise unsigned delivery.
     *
     * @param  array<string,mixed>  $event
     */
    public function deliverWebhook(PaymentWebhooks $webhooks, array $event, #[\SensitiveParameter] ?string $secret = null, ?int $timestamp = null): void
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = $secret === null ? '' : 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $webhooks->process($payload, $signature);
    }

    /** @return StripeIntentState */
    private function intentState(mixed $result): array
    {
        return $this->paymentState($result);
    }

    /** @return HostedCheckout */
    private function hostedCheckout(mixed $result): array
    {
        if (! is_array($result)
            || ! is_string($result['sessionId'] ?? null)
            || ! is_string($result['url'] ?? null)
            || ! (($result['expiresAt'] ?? null) instanceof CarbonImmutable)) {
            throw new TypeError('FakePaymentGateway requires complete, correctly typed HostedCheckout facts.');
        }

        return $result;
    }

    /** @return StripeCheckoutState */
    private function checkoutState(mixed $result): array
    {
        if (! is_array($result)
            || ! is_string($result['sessionId'] ?? null)
            || ! array_key_exists('paymentIntentId', $result)
            || ($result['paymentIntentId'] !== null && ! is_string($result['paymentIntentId']))
            || ! is_string($result['accountId'] ?? null)
            || ! is_bool($result['livemode'] ?? null)
            || ! is_int($result['amountMinor'] ?? null)
            || ! is_string($result['currency'] ?? null)
            || ! is_string($result['status'] ?? null)
            || ! is_string($result['paymentStatus'] ?? null)
            || ! array_key_exists('orderReference', $result)
            || ($result['orderReference'] !== null && ! is_string($result['orderReference']))
            || ! array_key_exists('orderRevision', $result)
            || ($result['orderRevision'] !== null && ! is_string($result['orderRevision']))
            || ! (($result['expiresAt'] ?? null) instanceof CarbonImmutable)
            || ! array_key_exists('operationKey', $result)
            || ($result['operationKey'] !== null && ! is_string($result['operationKey']))
            || ! array_key_exists('url', $result)
            || ($result['url'] !== null && ! is_string($result['url']))) {
            throw new TypeError('FakePaymentGateway requires complete, correctly typed StripeCheckoutState facts.');
        }

        return $result;
    }

    /** @return StripePaymentState */
    private function paymentState(mixed $result): array
    {
        if (! is_array($result)
            || ! array_key_exists('paymentIntentId', $result)
            || ($result['paymentIntentId'] !== null && ! is_string($result['paymentIntentId']))
            || ! array_key_exists('chargeId', $result)
            || ($result['chargeId'] !== null && ! is_string($result['chargeId']))
            || ! is_string($result['accountId'] ?? null)
            || ! is_bool($result['livemode'] ?? null)
            || ! is_int($result['amountMinor'] ?? null)
            || ! is_int($result['capturedAmountMinor'] ?? null)
            || ! is_int($result['refundedAmountMinor'] ?? null)
            || ! is_string($result['currency'] ?? null)
            || ! is_string($result['status'] ?? null)
            || ! is_string($result['captureMethod'] ?? null)
            || ! array_key_exists('amountCapturableMinor', $result)
            || ($result['amountCapturableMinor'] !== null && ! is_int($result['amountCapturableMinor']))
            || ! array_key_exists('paymentMethodType', $result)
            || ($result['paymentMethodType'] !== null && ! is_string($result['paymentMethodType']))
            || ! array_key_exists('partialAuthorizationStatus', $result)
            || ($result['partialAuthorizationStatus'] !== null && ! is_string($result['partialAuthorizationStatus']))) {
            throw new TypeError('FakePaymentGateway requires complete, correctly typed StripePaymentState facts.');
        }

        $this->validatePaymentExtras($result);

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $state
     *
     * @phpstan-assert array{releasedHoldRefundIds?:list<string>,cardBrand?:?string,cardLastFour?:?string,cardExpiryMonth?:?int,cardExpiryYear?:?int,wallet?:?string,providerOccurredAt?:?int,failureCode?:?string,failureReference?:?string,clientSecret?:?string,operationKey?:?string,...} $state
     */
    private function validatePaymentExtras(array $state): void
    {
        foreach (['cardBrand', 'cardLastFour', 'wallet', 'failureCode', 'failureReference', 'clientSecret', 'operationKey'] as $field) {
            if (isset($state[$field]) && ! is_string($state[$field])) {
                throw new TypeError('FakePaymentGateway requires string values for payment detail '.$field.'.');
            }
        }
        foreach (['cardExpiryMonth', 'cardExpiryYear', 'providerOccurredAt'] as $field) {
            if (isset($state[$field]) && ! is_int($state[$field])) {
                throw new TypeError('FakePaymentGateway requires integer values for payment detail '.$field.'.');
            }
        }
        if (array_key_exists('releasedHoldRefundIds', $state)) {
            $ids = $state['releasedHoldRefundIds'];
            if (! is_array($ids) || array_values(array_filter($ids, is_string(...))) !== $ids) {
                throw new TypeError('FakePaymentGateway requires released hold refund IDs as a list of strings.');
            }
        }
    }

    /** @return StripeRefundState */
    private function refundState(mixed $result): array
    {
        if (! is_array($result)
            || ! is_string($result['refundId'] ?? null)
            || ! array_key_exists('paymentIntentId', $result)
            || ($result['paymentIntentId'] !== null && ! is_string($result['paymentIntentId']))
            || ! array_key_exists('chargeId', $result)
            || ($result['chargeId'] !== null && ! is_string($result['chargeId']))
            || ! is_string($result['accountId'] ?? null)
            || ! is_bool($result['livemode'] ?? null)
            || ! is_int($result['amountMinor'] ?? null)
            || ! is_string($result['currency'] ?? null)
            || ! is_string($result['status'] ?? null)
            || ! array_key_exists('reason', $result)
            || ($result['reason'] !== null && ! is_string($result['reason']))
            || ! array_key_exists('operationKey', $result)
            || ($result['operationKey'] !== null && ! is_string($result['operationKey']))) {
            throw new TypeError('FakePaymentGateway requires complete, correctly typed StripeRefundState facts.');
        }

        $this->validateRefundTimestamp($result);

        return $result;
    }

    /**
     * @param  array<array-key,mixed>  $state
     *
     * @phpstan-assert array{providerOccurredAt?:?int,...} $state
     */
    private function validateRefundTimestamp(array $state): void
    {
        if (isset($state['providerOccurredAt']) && ! is_int($state['providerOccurredAt'])) {
            throw new TypeError('FakePaymentGateway requires an integer provider refund timestamp.');
        }
    }

    /**
     * Declare the exact native PaymentGateway method allowlist.
     *
     * @return list<string>
     *
     * @internal
     */
    protected function fakeMethods(): array
    {
        return ['createCheckout', 'expireCheckout', 'checkout', 'payment', 'resolveExisting', 'capture', 'cancel', 'refund', 'refunds', 'createIntent', 'intent', 'createCustomer', 'readRefund'];
    }
}
