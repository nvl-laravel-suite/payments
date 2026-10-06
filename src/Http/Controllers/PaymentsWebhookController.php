<?php

declare(strict_types=1);

namespace Nvl\Payments\Http\Controllers;

use DomainException;
use Illuminate\Http\Request;
use Nvl\Payments\Actions\ProcessPaymentsWebhookAction;
use Stripe\Event;
use Symfony\Component\HttpFoundation\Response;

/** Accept authenticated Stripe deliveries for order payments. */
final class PaymentsWebhookController
{
    /** Delegate verified financial evidence to the ingestion boundary. */
    public function __invoke(Request $request, ProcessPaymentsWebhookAction $action): Response
    {
        $event = $request->attributes->get('nvl-payments.stripe_event');
        abort_unless($event instanceof Event, 400);
        try {
            $action->execute($event);
        } catch (DomainException) {
            abort(400, 'Payment correlation mismatch.');
        }

        return new Response('OK');
    }
}
