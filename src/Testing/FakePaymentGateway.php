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
     * * Return the next scripted Checkout session for the exact order and operation parameters.
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
            'idempotencyKey' => $idempotencyKey,
        ]);

        if (! is_array($result)) {
            throw new TypeError('FakePaymentGateway::createCheckout requires scripted hosted checkout facts.');
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
     * * Return the next scripted Checkout state without retrieving a remote session.
     *
     * @return StripeCheckoutState
     */
    public function checkout(string $sessionId): array
    {
        $result = $this->invoke('checkout', ['sessionId' => $sessionId]);

        if (! is_array($result)) {
            throw new TypeError('FakePaymentGateway::checkout requires scripted checkout facts.');
        }

        return $result;
    }

    /**
     * * Return the next scripted payment state for the supplied reference.
     *
     * @return StripePaymentState
     */
    public function payment(string $paymentReference): array
    {
        $result = $this->invoke('payment', ['paymentReference' => $paymentReference]);

        if (! is_array($result)) {
            throw new TypeError('FakePaymentGateway::payment requires scripted payment facts.');
        }

        return $result;
    }

    /**
     * * Return the next scripted existing-payment resolution without another gateway call.
     *
     * @return StripePaymentState
     */
    public function resolveExisting(string $reference): array
    {
        $result = $this->invoke('resolveExisting', ['reference' => $reference]);

        if (! is_array($result)) {
            throw new TypeError('FakePaymentGateway::resolveExisting requires scripted payment facts.');
        }

        return $result;
    }

    /**
     * * Return the next scripted capture state for the exact minor amount and idempotency key.
     *
     * @return StripePaymentState
     */
    public function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): array
    {
        $result = $this->invoke('capture', [
            'paymentIntentId' => $paymentIntentId,
            'amountMinor' => $amountMinor,
            'idempotencyKey' => $idempotencyKey,
        ]);

        if (! is_array($result)) {
            throw new TypeError('FakePaymentGateway::capture requires scripted payment facts.');
        }

        return $result;
    }

    /**
     * * Return the next scripted cancellation state without mutating authorization evidence.
     *
     * @return StripePaymentState
     */
    public function cancel(string $paymentIntentId, string $idempotencyKey): array
    {
        $result = $this->invoke('cancel', [
            'paymentIntentId' => $paymentIntentId,
            'idempotencyKey' => $idempotencyKey,
        ]);

        if (! is_array($result)) {
            throw new TypeError('FakePaymentGateway::cancel requires scripted payment facts.');
        }

        return $result;
    }

    /**
     * * Return the next scripted refund state for the exact requested refund parameters.
     *
     * @return StripeRefundState
     */
    public function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): array
    {
        $result = $this->invoke('refund', [
            'paymentId' => $paymentId,
            'amountMinor' => $amountMinor,
            'reason' => $reason,
            'idempotencyKey' => $idempotencyKey,
        ]);

        if (! is_array($result)) {
            throw new TypeError('FakePaymentGateway::refund requires scripted refund facts.');
        }

        return $result;
    }

    /**
     * Return the next scripted refund list without retrieving remote refunds.
     *
     * @return list<StripeRefundState>
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
