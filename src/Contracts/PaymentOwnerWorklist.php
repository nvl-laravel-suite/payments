<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Enumerates explicit native payment owners for independently authorized bounded maintenance. @api */
interface PaymentOwnerWorklist
{
    /** @return iterable<OwnerReference> */
    public function owners(?string $attemptId, int $limit): iterable;
}
