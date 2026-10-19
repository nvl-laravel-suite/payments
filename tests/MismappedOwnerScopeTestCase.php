<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests;

use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;

/** Keeps an intentionally mismapped provider receipt backfilled for read-only verification. */
abstract class MismappedOwnerScopeTestCase extends LegacyOwnerScopeTestCase
{
    /** @return list<OwnershipMapping> */
    protected function adoptionMappings(): array
    {
        $mappings = parent::adoptionMappings();
        $foreign = $this->app->make(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(8));

        return array_map(static fn (OwnershipMapping $mapping): OwnershipMapping => $mapping->resource === 'payments.webhooks'
            ? new OwnershipMapping($mapping->resource, $mapping->recordId, $foreign) : $mapping, $mappings);
    }

    /** Leave the actual reviewed plan unactivated so verification can reject it. */
    protected function activatePreparedPlan(OwnershipAdoptionCoordinator $coordinator, OwnershipAdoptionPlan $plan, OwnershipOperation $operation): void {}
}
