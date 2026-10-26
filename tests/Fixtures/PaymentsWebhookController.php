<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Illuminate\Http\Request;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Services\PaymentWebhooks;
use Symfony\Component\HttpFoundation\Response;

/** Consumer-owned signed webhook endpoint for transport scenarios. */
final class PaymentsWebhookController
{
    public function __invoke(Request $request, PaymentWebhooks $webhooks): Response
    {
        abort_unless(config('nvl-payments.enabled') === true, 404);
        abort_unless(is_string(config('nvl-payments.stripe.webhook_secret')) && config('nvl-payments.stripe.webhook_secret') !== '', 503);
        try {
            $webhooks->process($request->getContent(), $request->header('Stripe-Signature', ''));
        } catch (PaymentsException) {
            abort(400, 'Invalid Payments webhook.');
        }

        return new Response('OK');
    }
}
