<?php

declare(strict_types=1);

namespace Nvl\Payments\Models\Concerns;

use Nvl\Support\Config\PackageStorage;

/** Keeps Payments records on the configured application connection. */
trait UsesPaymentsConnection
{
    /** Resolve Payments' explicit connection or Laravel's default. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('payments') ?? parent::getConnectionName());
    }
}
