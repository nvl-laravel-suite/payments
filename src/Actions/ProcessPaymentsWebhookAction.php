<?php

declare(strict_types=1);

namespace Nvl\Payments\Actions;

use DomainException;
use Illuminate\Support\Facades\Config;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentWebhookEvent;
use Nvl\Payments\Services\PaymentStateSyncer;
use Stripe\Event;

/**
 * Ingest verified events with remote reads outside the atomic local transition.
 * The verified Stripe event is the input contract; this boundary returns no host projection.
 */
final class ProcessPaymentsWebhookAction
{
    private const array PAYMENT_EVENTS = ['payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.payment_failed', 'payment_intent.amount_capturable_updated', 'payment_intent.canceled', 'charge.refunded'];

    private const array CHECKOUT_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'];

    /** Inject the authoritative remote reader and reusable state boundary. */
    public function __construct(private readonly PaymentGateway $gateway, private readonly PaymentStateSyncer $syncer) {}

    /** Persist a signed delivery once, allowing failed remote reads to be retried. */
    public function execute(Event $event): void
    {
        $record = PaymentWebhookEvent::firstOrCreate(['stripe_event_id' => $event->id], [
            'type' => $event->type, 'status' => 'pending', 'stripe_object_id' => $event->data->object->id,
            'stripe_account_id' => $event['account'], 'stripe_livemode' => $event->livemode,
        ]);
        if (in_array($record->status, ['processed', 'ignored'], true)) {
            return;
        }
        $checkoutEvent = in_array($event->type, self::CHECKOUT_EVENTS, true);
        $supported = $checkoutEvent || in_array($event->type, self::PAYMENT_EVENTS, true);
        $field = $checkoutEvent ? 'stripe_checkout_session_id' : ($event->type === 'charge.refunded' ? 'stripe_charge_id' : 'stripe_payment_intent_id');
        $attempt = $supported ? PaymentAttempt::where($field, $record->stripe_object_id)->first() : null;
        $checkout = $attempt !== null && $checkoutEvent && is_string($record->stripe_object_id) ? $this->gateway->checkout($record->stripe_object_id) : null;
        $reference = $checkout !== null ? $checkout->paymentIntentId : ($checkoutEvent ? null : $record->stripe_object_id);
        $payment = $attempt !== null && $reference !== null ? $this->gateway->payment($reference) : null;
        $record->getConnection()->transaction(function () use ($record, $attempt, $checkout, $payment, $reference): void {
            $lockedEvent = PaymentWebhookEvent::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedEvent->status, ['processed', 'ignored'], true)) {
                return;
            }
            if ($attempt !== null) {
                $locked = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($checkout !== null && ($checkout->sessionId !== $locked->stripe_checkout_session_id || $checkout->orderReference !== $locked->order_reference
                    || $checkout->orderRevision !== $locked->order_revision || $checkout->amountMinor !== $locked->amount_minor
                    || strtolower($checkout->currency) !== strtolower($locked->currency) || $checkout->accountId !== Config::get('payments.stripe.account_id')
                    || $checkout->livemode !== Config::get('payments.stripe.livemode')
                    || ($locked->stripe_account_id !== null && $checkout->accountId !== $locked->stripe_account_id)
                    || ($locked->stripe_livemode !== null && $checkout->livemode !== $locked->stripe_livemode))) {
                    throw new DomainException('Checkout does not match the reserved attempt.');
                }
                if ($payment !== null) {
                    if ($reference !== $payment->paymentIntentId && $reference !== $payment->chargeId) {
                        throw new DomainException('Stripe returned a different payment.');
                    }
                    $this->syncer->assertMatches($locked, $payment);
                    if ($checkout !== null && $locked->stripe_payment_intent_id === null) {
                        $locked->update(['stripe_payment_intent_id' => $payment->paymentIntentId]);
                    }
                    $this->syncer->sync($payment);
                }
            }
            $lockedEvent->update(['status' => $payment !== null ? 'processed' : 'ignored', 'outcome' => $payment !== null ? 'synchronized' : 'no_correlated_payment', 'processed_at' => now()]);
        });
    }
}
