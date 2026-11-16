<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

/**
 * Additional provider operations for Payment Element.
 *
 * @api
 *
 * @phpstan-import-type StripePaymentState from PaymentGateway
 *
 * @phpstan-type StripeIntentState StripePaymentState
 */
interface PaymentIntentGateway extends PaymentGateway
{
    /**
     * @param  list<string>  $allowedMethods
     * @return StripeIntentState
     */
    public function createIntent(OrderPaymentSnapshot $order, string $captureMethod, array $allowedMethods, ?string $customerId, string $idempotencyKey): array;

    /** @return StripeIntentState */
    public function intent(string $intentId): array;
}
