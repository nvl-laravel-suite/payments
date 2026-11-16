<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Support\Facades\Config;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentRefundLookup;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentWebhookEvent;
use Nvl\Payments\Ownership\PaymentProviderCorrelation;
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
    private const array CHECKOUT_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'];

    private const array REFUND_EVENTS = ['charge.refund.updated', 'refund.created', 'refund.updated', 'refund.failed'];

    /** Inject the authoritative remote reader and reusable state boundary. */
    public function __construct(private readonly PaymentGateway $gateway, private readonly PaymentStateSyncer $syncer, private readonly PaymentsProviderAdmission $ownership, private readonly ?PaymentReconciliation $reconciliation = null) {}

    /** Verify the signed raw payload, account and mode before processing; neither argument enters an exception trace. */
    public function process(#[\SensitiveParameter] string $payload, #[\SensitiveParameter] string $signature): void
    {
        $secret = Config::get('nvl-payments.stripe.webhook_secret');
        if (Config::get('nvl-payments.enabled') !== true || ! is_string($secret) || trim($secret) === '') {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Payments webhook is unavailable.');
        }
        PaymentsAvailability::assertOutsideTransaction();
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
            || strlen($decoded['data']['object']['id']) > 255
            || (isset($decoded['created']) && (! is_int($decoded['created']) || $decoded['created'] < 0))
            || (isset($decoded['account']) && (! is_string($decoded['account']) || strlen($decoded['account']) > 255))) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Malformed Payments event.');
        }
        $event = Event::constructFrom([
            'id' => $decoded['id'], 'type' => $decoded['type'], 'livemode' => $decoded['livemode'],
            'account' => $decoded['account'] ?? null, 'created' => $decoded['created'] ?? null,
            'data' => ['object' => ['id' => $decoded['data']['object']['id']]],
        ]);
        if (($event->livemode && Config::get('nvl-payments.allow_live') !== true)
            || $event->livemode !== Config::get('nvl-payments.stripe.livemode')
            || ($event['account'] !== null && $event['account'] !== Config::get('nvl-payments.stripe.account_id'))) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Wrong Payments account or mode.');
        }
        $this->processEvent($event);
    }

    /** Resolve refund-object deliveries through authoritative Stripe identity before owner admission. */
    private function processEvent(Event $event): void
    {
        $reference = null;
        if ($this->gateway instanceof PaymentRefundLookup && in_array($event->type, self::REFUND_EVENTS, true)) {
            $objectId = $event->data->object->id ?? throw new \UnexpectedValueException('Missing Stripe refund ID.');
            $refund = $this->gateway->readRefund($objectId);
            if ($refund['refundId'] !== $objectId || $refund['livemode'] !== $event->livemode
                || $refund['accountId'] !== Config::get('nvl-payments.stripe.account_id')) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Stripe returned a different refund.');
            }
            $reference = $refund['paymentIntentId'] ?? $refund['chargeId'];
        }

        $this->ownership->run($event, fn () => $this->executeAdmitted($event, $reference), $reference);
    }

    /** Apply only inside the independently admitted stored payment owner. */
    private function executeAdmitted(Event $event, ?string $refundParent): void
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
        $field = $refundParent !== null
            ? (str_starts_with($refundParent, 'pi_') ? 'stripe_payment_intent_id' : 'stripe_charge_id')
            : PaymentProviderCorrelation::field($event->type);
        $attempt = $field === null ? null : PaymentsModelRegistry::attemptQuery()->where($field, $refundParent ?? $record->stripe_object_id)->first();
        if ($attempt !== null && ($refundParent !== null || in_array($event->type, ['charge.refunded', 'checkout.session.expired'], true))) {
            ($this->reconciliation ?? app(PaymentReconciliation::class))->reconcile($attempt);
            $record->update(['status' => 'processed', 'outcome' => 'synchronized', 'processed_at' => now()]);

            return;
        }
        $checkout = $attempt !== null && $checkoutEvent && is_string($record->stripe_object_id) ? $this->gateway->checkout($record->stripe_object_id) : null;
        $reference = $checkout !== null ? $checkout['paymentIntentId'] : ($checkoutEvent ? null : $record->stripe_object_id);
        $payment = $attempt !== null && $reference !== null ? $this->gateway->payment($reference) : null;
        $eventStatus = match ($event->type) {
            'payment_intent.succeeded' => 'succeeded',
            'payment_intent.canceled' => 'canceled',
            'payment_intent.payment_failed' => 'requires_payment_method',
            default => null,
        };
        if ($payment !== null && $eventStatus === $payment['status'] && is_int($event['created'])) {
            $payment['providerOccurredAt'] = $event['created'];
        }
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
