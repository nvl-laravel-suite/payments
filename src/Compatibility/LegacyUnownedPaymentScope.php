<?php

declare(strict_types=1);

namespace Nvl\Payments\Compatibility;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipScope;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Preserves historical globally unowned Payment access only before native adoption.
 * @implements Scope<Model>
 *
 * @internal
 */
final readonly class LegacyUnownedPaymentScope implements Scope
{
    /** Never let an old configuration bypass a compiled or persisted native partition. */
    public static function permitsLegacy(Model $model): bool
    {
        if (config('nvl-tenancy.enabled') !== true) {
            return false;
        }
        $resources = Container::getInstance()->make(OwnershipResourceRegistry::class);
        $key = null;
        foreach ($resources->all() as $resource) {
            if ($resource->model === $model::class) {
                if ($resource->ownership->strategy !== 'unscoped') {
                    return false;
                }
                $key = $resource->key;
                break;
            }
        }
        if ($key === null) {
            $resources->assertUnclaimed($model);
        }
        foreach (Container::getInstance()->make(TenantResourceRegistry::class)->all() as $legacy) {
            $canonical = new $legacy->model;
            if ($canonical->getConnection() === $model->getConnection() && $canonical->getTable() === $model->getTable()) {
                return false;
            }
        }
        $schema = $model->getConnection()->getSchemaBuilder();
        foreach (['tenant_id', 'ownership_key', 'owner_scope_key'] as $column) {
            if ($schema->hasColumn($model->getTable(), $column)) {
                throw new OwnershipViolation('Tagged payment storage cannot use the historical global fallback.');
            }
        }
        (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), resource: $key, physicalTable: $model->getTable());

        return true;
    }

    /** @param Builder<covariant Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        if (! self::permitsLegacy($model)) {
            (new OwnershipScope)->apply($builder, $model);
        }
    }
}
