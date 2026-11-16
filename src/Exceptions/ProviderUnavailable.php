<?php

declare(strict_types=1);

namespace Nvl\Payments\Exceptions;

/** A payment provider failure that can be retried with the same operation identity.
 *
 * @api
 */
class ProviderUnavailable extends PaymentsException
{
    public function __construct(public readonly ?string $stripeCode = null, public readonly ?int $httpStatus = null)
    {
        parent::__construct('Stripe is temporarily unavailable for Payments.');
    }
}
