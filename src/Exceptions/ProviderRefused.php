<?php

declare(strict_types=1);

namespace Nvl\Payments\Exceptions;

/** A nonretryable payment provider refusal.
 *
 * @api
 */
class ProviderRefused extends PaymentsException
{
    public function __construct(public readonly ?string $stripeCode, public readonly ?int $httpStatus = null)
    {
        parent::__construct('Stripe refused the Payments operation.');
    }
}
