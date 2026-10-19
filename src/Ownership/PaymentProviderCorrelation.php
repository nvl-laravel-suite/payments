<?php

declare(strict_types=1);

namespace Nvl\Payments\Ownership;

/** Names the closed provider event-to-persisted-payment correlation fields. @internal */
final readonly class PaymentProviderCorrelation
{
    /** Select the stored identity field without consulting event metadata or ambient owners. */
    public static function field(string $type): ?string
    {
        return match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed' => 'stripe_checkout_session_id',
            'payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.payment_failed', 'payment_intent.amount_capturable_updated', 'payment_intent.canceled' => 'stripe_payment_intent_id',
            'charge.refunded' => 'stripe_charge_id',
            default => null,
        };
    }
}
