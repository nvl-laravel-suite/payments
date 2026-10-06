<?php

declare(strict_types=1);

namespace Nvl\Payments\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\ValueObjects\HostedCheckout;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;
use Nvl\Support\Testing\FakeCalls;
use TypeError;

/**
 * Supplies explicit one-time payment responses without Stripe, storage, or lifecycle execution.
 *
 * @api
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
     */
    public function createCheckout(OrderPaymentSnapshot $order, string $captureMethod, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $idempotencyKey): HostedCheckout
    {
        $result = $this->invoke('createCheckout', [
            'order' => $order,
            'captureMethod' => $captureMethod,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'expiresAt' => $expiresAt,
            'idempotencyKey' => $idempotencyKey,
        ]);

        if (! $result instanceof HostedCheckout) {
            throw new TypeError('FakePaymentGateway::createCheckout requires a scripted HostedCheckout.');
        }

        return $result;
    }

    /**
     * Consume the next expiration script while retaining the native void result.
     */
    public function expireCheckout(string $sessionId, string $idempotencyKey): void
    {
        $this->invoke('expireCheckout', ['sessionId' => $sessionId, 'idempotencyKey' => $idempotencyKey]);
    }

    /**
     * Return the next scripted Checkout state without retrieving a remote session.
     */
    public function checkout(string $sessionId): StripeCheckoutState
    {
        $result = $this->invoke('checkout', ['sessionId' => $sessionId]);

        if (! $result instanceof StripeCheckoutState) {
            throw new TypeError('FakePaymentGateway::checkout requires a scripted StripeCheckoutState.');
        }

        return $result;
    }

    /**
     * Return the next scripted payment state for the supplied reference.
     */
    public function payment(string $paymentReference): StripePaymentState
    {
        $result = $this->invoke('payment', ['paymentReference' => $paymentReference]);

        if (! $result instanceof StripePaymentState) {
            throw new TypeError('FakePaymentGateway::payment requires a scripted StripePaymentState.');
        }

        return $result;
    }

    /**
     * Return the next scripted existing-payment resolution without another gateway call.
     */
    public function resolveExisting(string $reference): StripePaymentState
    {
        $result = $this->invoke('resolveExisting', ['reference' => $reference]);

        if (! $result instanceof StripePaymentState) {
            throw new TypeError('FakePaymentGateway::resolveExisting requires a scripted StripePaymentState.');
        }

        return $result;
    }

    /**
     * Return the next scripted capture state for the exact minor amount and idempotency key.
     */
    public function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): StripePaymentState
    {
        $result = $this->invoke('capture', [
            'paymentIntentId' => $paymentIntentId,
            'amountMinor' => $amountMinor,
            'idempotencyKey' => $idempotencyKey,
        ]);

        if (! $result instanceof StripePaymentState) {
            throw new TypeError('FakePaymentGateway::capture requires a scripted StripePaymentState.');
        }

        return $result;
    }

    /**
     * Return the next scripted cancellation state without mutating authorization evidence.
     */
    public function cancel(string $paymentIntentId, string $idempotencyKey): StripePaymentState
    {
        $result = $this->invoke('cancel', [
            'paymentIntentId' => $paymentIntentId,
            'idempotencyKey' => $idempotencyKey,
        ]);

        if (! $result instanceof StripePaymentState) {
            throw new TypeError('FakePaymentGateway::cancel requires a scripted StripePaymentState.');
        }

        return $result;
    }

    /**
     * Return the next scripted refund state for the exact requested refund parameters.
     */
    public function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): StripeRefundState
    {
        $result = $this->invoke('refund', [
            'paymentId' => $paymentId,
            'amountMinor' => $amountMinor,
            'reason' => $reason,
            'idempotencyKey' => $idempotencyKey,
        ]);

        if (! $result instanceof StripeRefundState) {
            throw new TypeError('FakePaymentGateway::refund requires a scripted StripeRefundState.');
        }

        return $result;
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
            throw new TypeError('FakePaymentGateway::refunds requires a scripted list of StripeRefundState.');
        }

        $refunds = [];
        foreach ($result as $refund) {
            if (! $refund instanceof StripeRefundState) {
                throw new TypeError('FakePaymentGateway::refunds requires a scripted list of StripeRefundState.');
            }

            $refunds[] = $refund;
        }

        return $refunds;
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
