<?php

declare(strict_types=1);

namespace Nvl\Payments\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Return 404 while the Stripe webhook receiver is disabled or has no signing secret.
 *
 * @api
 */
class RequirePaymentWebhookSecret
{
    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('nvl-payments.stripe.webhook_secret');
        abort_unless(config('nvl-payments.enabled') === true && is_string($secret) && trim($secret) !== '', 404);

        return $next($request);
    }
}
