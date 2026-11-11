<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use DomainException;
use Illuminate\Support\Facades\Config;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentWebhookEvent;
use Nvl\Payments\Ownership\PaymentsProviderAdmission;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;

/**
 * Authenticate raw Stripe deliveries before admitting an event to the local transition.
 *
 * @api
 */
final class PaymentWebhooks
{
    private const array PAYMENT_EVENTS = ['payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.payment_failed', 'payment_intent.amount_capturable_updated', 'payment_intent.canceled', 'charge.refunded'];

    private const array CHECKOUT_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'];

    /** Inject the authoritative remote reader and reusable state boundary. */
    public function __construct(private readonly PaymentGateway $gateway, private readonly PaymentStateSyncer $syncer, private readonly PaymentsProviderAdmission $ownership) {}

    /** Verify the signed raw payload, account and mode before processing; neither argument enters an exception trace. */
    public function process(#[\SensitiveParameter] string $payload, #[\SensitiveParameter] string $signature): void
    {
        $secret = Config::get('nvl-payments.stripe.webhook_secret');
        if (Config::get('nvl-payments.enabled') !== true || ! is_string($secret) || $secret === '') {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Payments webhook is unavailable.');
        }
        if (PaymentsModelRegistry::newAttempt()->getConnection()->transactionLevel() !== 0) {
            throw new DomainException('Payments webhook processing must start outside a database transaction.');
        }
        try {
            WebhookSignature::verifyHeader($payload, $signature, $secret, 300);
        } catch (SignatureVerificationException) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Invalid Payments webhook.');
        }
        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! is_string($decoded['id'] ?? null) || ! str_starts_with($decoded['id'], 'evt_')
            || strlen($decoded['id']) > 255 || ! is_string($decoded['type'] ?? null) || strlen($decoded['type']) > 255
            || ! is_bool($decoded['livemode'] ?? null) || ! is_array($decoded['data'] ?? null)
            || ! is_array($decoded['data']['object'] ?? null) || ! is_string($decoded['data']['object']['id'] ?? null)
            || strlen($decoded['data']['object']['id']) > 255) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Malformed Payments event.');
        }
        $event = Event::constructFrom($decoded);
        if ($event->livemode !== Config::get('nvl-payments.stripe.livemode')
            || ($event['account'] !== null && $event['account'] !== Config::get('nvl-payments.stripe.account_id'))) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Wrong Payments account or mode.');
        }
        $this->processEvent($event);
    }

    /** Persist a signed delivery once, allowing failed remote reads to be retried. */
    private function processEvent(Event $event): void
    {
        $this->ownership->run($event, fn () => $this->executeAdmitted($event));
    }

    /** Apply only inside the independently admitted stored payment owner. */
    private function executeAdmitted(Event $event): void
    {
        $record = PaymentWebhookEvent::firstOrCreate(['stripe_event_id' => $event->id], [
            'type' => $event->type, 'status' => 'pending', 'stripe_object_id' => $event->data->object->id,
            'stripe_account_id' => $event['account'], 'stripe_livemode' => $event->livemode,
        ]);
        $this->ownership->assertReceipt($record, $event);
        if (in_array($record->status, ['processed', 'ignored'], true)) {
            return;
        }
        $checkoutEvent = in_array($event->type, self::CHECKOUT_EVENTS, true);
        $supported = $checkoutEvent || in_array($event->type, self::PAYMENT_EVENTS, true);
        $field = $checkoutEvent ? 'stripe_checkout_session_id' : ($event->type === 'charge.refunded' ? 'stripe_charge_id' : 'stripe_payment_intent_id');
        $attempt = $supported ? PaymentsModelRegistry::attemptQuery()->where($field, $record->stripe_object_id)->first() : null;
        $checkout = $attempt !== null && $checkoutEvent && is_string($record->stripe_object_id) ? $this->gateway->checkout($record->stripe_object_id) : null;
        $reference = $checkout !== null ? $checkout['paymentIntentId'] : ($checkoutEvent ? null : $record->stripe_object_id);
        $payment = $attempt !== null && $reference !== null ? $this->gateway->payment($reference) : null;
        $record->getConnection()->transaction(function () use ($record, $attempt, $checkout, $payment, $reference): void {
            $lockedEvent = PaymentWebhookEvent::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedEvent->status, ['processed', 'ignored'], true)) {
                return;
            }
            if ($attempt !== null) {
                $locked = PaymentsModelRegistry::attemptQuery()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($checkout !== null && ($checkout['sessionId'] !== $locked->stripe_checkout_session_id || $checkout['orderReference'] !== $locked->order_reference
                    || $checkout['orderRevision'] !== $locked->order_revision || $checkout['amountMinor'] !== $locked->amount_minor
                    || strtolower($checkout['currency']) !== strtolower($locked->currency) || $checkout['accountId'] !== Config::get('nvl-payments.stripe.account_id')
                    || $checkout['livemode'] !== Config::get('nvl-payments.stripe.livemode')
                    || ($locked->stripe_account_id !== null && $checkout['accountId'] !== $locked->stripe_account_id)
                    || ($locked->stripe_livemode !== null && $checkout['livemode'] !== $locked->stripe_livemode))) {
                    throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Checkout does not match the reserved attempt.');
                }
                if ($payment !== null) {
                    if ($reference !== $payment['paymentIntentId'] && $reference !== $payment['chargeId']) {
                        throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe returned a different payment.');
                    }
                    $this->syncer->assertMatches($locked, $payment);
                    if ($checkout !== null && $locked->stripe_payment_intent_id === null) {
                        $locked->update(['stripe_payment_intent_id' => $payment['paymentIntentId']]);
                    }
                    $this->syncer->sync($payment);
                }
            }
            $lockedEvent->update(['status' => $payment !== null ? 'processed' : 'ignored', 'outcome' => $payment !== null ? 'synchronized' : 'no_correlated_payment', 'processed_at' => now()]);
        });
    }
}
