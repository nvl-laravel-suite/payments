<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use DomainException;

/**
 * Local Stripe configuration and payments-connection policy.
 *
 * @api
 */
class PaymentsAvailability
{
    /** Determine if this deployment can open a payment with the configured Stripe credentials. */
    public function acceptsPayments(): bool
    {
        $key = config('nvl-payments.stripe.secret');
        $account = config('nvl-payments.stripe.account_id');

        return config('nvl-payments.enabled') === true && is_string($key) && self::allowsKey($key)
            && is_string($account) && str_starts_with($account, 'acct_');
    }

    /** @internal */
    public static function allowsKey(string $key): bool
    {
        $live = str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_');

        return preg_match('/^(sk|rk)_(test|live)_\S+$/D', $key) === 1
            && $live === config('nvl-payments.stripe.livemode', false)
            && (! $live || config('nvl-payments.allow_live', false) === true);
    }

    /** @internal */
    public static function assertOutsideTransaction(): void
    {
        if ((PaymentsModelRegistry::newAttempt())->getConnection()->transactionLevel() > 0) {
            throw new DomainException('Stripe operations must run outside a database transaction on the payments connection.');
        }
    }
}
