<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\ValueObjects\PaymentSnapshot;

/**
 * Substitutable boundary for the supported CancelAuthorization workflow.
 *
 * @api
 */
interface CancelAuthorizationContract
{
    /** Perform the authorized operation once and return synchronized financial facts. */
    public function execute(string $attemptId, Authenticatable $actor, string $operationId): PaymentSnapshot;
}
