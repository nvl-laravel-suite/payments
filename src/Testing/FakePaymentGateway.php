<?php

declare(strict_types=1);

namespace Nvl\Payments\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Nvl\Payments\Contracts\PaymentGateway;
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
 * @phpstan-import-type StripeRefundState from \Nvl\Payments\Contracts\PaymentGateway
 */
final class FakePaymentGateway implements PaymentGateway
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
        $result = $this->invoke('cancel', [
            'paymentIntentId' => $paymentIntentId,
            'idempotency_key' => $idempotencyKey,
        ]);

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

        return $result;
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

        return $result;
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
        return ['createCheckout', 'expireCheckout', 'checkout', 'payment', 'resolveExisting', 'capture', 'cancel', 'refund', 'refunds'];
    }
}
