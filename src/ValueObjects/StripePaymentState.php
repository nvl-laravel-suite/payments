<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

/** Canonical Stripe payment facts read from the payment gateway. */
final readonly class StripePaymentState
{
    /** Hold a resolved PaymentIntent or Charge and its financial state. */
    public function __construct(
        public ?string $paymentIntentId,
        public ?string $chargeId,
        public string $accountId,
        public bool $livemode,
        public int $amountMinor,
        public int $capturedAmountMinor,
        public int $refundedAmountMinor,
        public string $currency,
        public string $status,
        public string $captureMethod,
        public ?int $amountCapturableMinor = null,
        public ?string $paymentMethodType = null,
        public ?string $partialAuthorizationStatus = null,
    ) {}
}
