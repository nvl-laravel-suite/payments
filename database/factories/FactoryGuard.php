<?php

declare(strict_types=1);

namespace Nvl\Payments\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;

/** Checks canonical payment factory parents without importing tenant lifecycle helpers. @internal */
final readonly class FactoryGuard
{
    /** Require an unchanged native scalar before fixture lookup. */
    public static function identifier(mixed $key): int|string
    {
        if ((! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('Payment fixtures require a nonempty native scalar identifier.');
        }

        return $key;
    }

    /** Validate the concrete persisted parent in its actual native ownership source. */
    public static function parent(Model $parent, Model $child): void
    {
        $key = self::identifier($parent->getKey());
        $canonical = new ($parent::class);
        if (! $parent->exists || $parent->getRawOriginal($parent->getKeyName()) !== $key || $parent->getTable() !== $canonical->getTable()
            || $parent->getConnection() !== $canonical->getConnection() || $parent->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Payment fixture parents require unchanged canonical persistence on their actual source.');
        }
        $container = Container::getInstance();
        $resource = $container->make(OwnershipResourceRegistry::class)->forModel($parent);
        if ($resource->ownership->strategy === 'unscoped') {
            (new PersistedOwnershipStorage)->assertUnadopted($parent->getConnection(), resource: $resource->key, physicalTable: $parent->getTable());
        } else {
            $container->make(OwnershipBoundary::class)->assertRecord($parent, $resource->key);
        }
    }
}
