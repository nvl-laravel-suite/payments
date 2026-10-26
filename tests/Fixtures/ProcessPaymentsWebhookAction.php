<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Nvl\Payments\Services\PaymentWebhooks;
use Stripe\Event;

/** Consumer signs its provider fixture and invokes the raw service boundary. */
final class ProcessPaymentsWebhookAction
{
    public function __construct(private readonly PaymentWebhooks $webhooks) {}

    public function execute(Event $event): void
    {
        $payload = $event->toJSON();
        $timestamp = time();
        $secret = config('nvl-payments.stripe.webhook_secret') ?: 'whsec_consumer';
        config(['nvl-payments.stripe.webhook_secret' => $secret]);
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $this->webhooks->process($payload, $signature);
    }
}
