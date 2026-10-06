<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\ValueObjects\PaymentSnapshot;

/**
 * Substitutable boundary for the supported ResolvePaymentException workflow.
 *
 * @api
 */
interface ResolvePaymentExceptionContract
{
    /** Accept a confirmed payment exception once with an immutable actor and operation UUID. */
    public function execute(string $attemptId, Authenticatable $actor, string $operationId): PaymentSnapshot;
}
