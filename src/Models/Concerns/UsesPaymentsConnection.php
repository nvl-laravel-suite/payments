<?php

declare(strict_types=1);

namespace Nvl\Payments\Models\Concerns;

use InvalidArgumentException;

/** Keeps Payments records on the configured application connection. */
trait UsesPaymentsConnection
{
    /** Resolve Payments' explicit connection or Laravel's default. */
    public function getConnectionName(): ?string
    {
        $connection = config('payments.connection');

        if ($connection !== null && ! is_string($connection)) {
            throw new InvalidArgumentException('payments.connection must be null or a connection name.');
        }

        return $connection ?? parent::getConnectionName();
    }
}
