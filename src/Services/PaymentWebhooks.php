<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Support\Facades\Config;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
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
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentStateSyncer $syncer,
        private readonly PaymentsProviderAdmission $ownership,
        private readonly ?PaymentReconciliation $reconciliation = null,
        private readonly ?PaymentWebhookInbox $inbox = null,
    ) {}

    /** @return list<string> Exact Stripe events required by the package. */
    public static function eventTypes(): array
    {
        return [...PaymentWebhookProcessor::PAYMENT_EVENTS, ...PaymentWebhookProcessor::CHECKOUT_EVENTS, ...PaymentWebhookProcessor::REFUND_EVENTS];
    }

    /** Verify the signed raw payload, account and mode before processing; neither argument enters an exception trace. */
    public function process(#[\SensitiveParameter] string $payload, #[\SensitiveParameter] string $signature): void
    {
        $this->intake($payload, $signature, false);
    }

    /** Persist an authenticated receipt for an asynchronous worker. */
    public function enqueue(#[\SensitiveParameter] string $payload, #[\SensitiveParameter] string $signature): void
    {
        $this->intake($payload, $signature, true);
    }

    private function intake(#[\SensitiveParameter] string $payload, #[\SensitiveParameter] string $signature, bool $defer): void
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
        if ($event['account'] !== null && $event['account'] !== Config::get('nvl-payments.stripe.account_id')) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Wrong Payments account.');
        }
        $processor = $defer ? null : new PaymentWebhookProcessor(
            $this->gateway, $this->syncer, $this->ownership, $this->reconciliation ?? app(PaymentReconciliation::class),
        );
        ($this->inbox ?? app(PaymentWebhookInbox::class))->receive($event, $defer, $processor);
    }
}
