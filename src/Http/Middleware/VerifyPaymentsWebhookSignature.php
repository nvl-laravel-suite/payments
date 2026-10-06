<?php

declare(strict_types=1);

namespace Nvl\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/** Authenticate raw Stripe deliveries with the Payments-specific signing secret. */
final class VerifyPaymentsWebhookSignature
{
    /** Verify freshness and the event envelope before resolving application handlers. */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Config::get('nvl-payments.enabled') === true, 404);
        $secret = Config::get('nvl-payments.stripe.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        try {
            WebhookSignature::verifyHeader($request->getContent(), $request->header('Stripe-Signature', ''), $secret, 300);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            abort(400, 'Invalid Payments webhook.');
        }
        $payload = json_decode($request->getContent(), true);
        abort_unless(is_array($payload) && is_string($payload['id'] ?? null) && str_starts_with($payload['id'], 'evt_')
            && strlen($payload['id']) <= 255 && is_string($payload['type'] ?? null) && strlen($payload['type']) <= 255
            && is_bool($payload['livemode'] ?? null) && is_array($payload['data'] ?? null)
            && is_array($payload['data']['object'] ?? null) && is_string($payload['data']['object']['id'] ?? null)
            && strlen($payload['data']['object']['id']) <= 255, 400, 'Malformed Payments event.');
        $event = Event::constructFrom($payload);
        abort_unless($event->livemode === Config::get('nvl-payments.stripe.livemode')
            && ($event['account'] === null || $event['account'] === Config::get('nvl-payments.stripe.account_id')), 400, 'Wrong Payments account or mode.');
        $request->attributes->set('nvl-payments.stripe_event', $event);

        $response = $next($request);

        if (! $response instanceof Response) {
            throw new UnexpectedValueException('Payments webhook middleware expected an HTTP response.');
        }

        return $response;
    }
}
