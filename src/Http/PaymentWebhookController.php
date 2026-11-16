<?php

declare(strict_types=1);

namespace Nvl\Payments\Http;

use Illuminate\Http\Request;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Services\PaymentWebhooks;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
final class PaymentWebhookController
{
    public function __invoke(Request $request, PaymentWebhooks $webhooks): Response
    {
        try {
            $webhooks->enqueue($request->getContent(), $request->header('Stripe-Signature', ''));
        } catch (PaymentsException) {
            return response('', 400);
        }

        return response('', 204);
    }
}
