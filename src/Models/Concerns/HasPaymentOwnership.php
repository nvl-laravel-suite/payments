<?php

declare(strict_types=1);

namespace Nvl\Payments\Models\Concerns;

use Nvl\Support\Ownership\Traits\HasOwnership;

/** Applies the canonical native ownership boundary to Payment records. @internal */
trait HasPaymentOwnership
{
    use HasOwnership;
}
