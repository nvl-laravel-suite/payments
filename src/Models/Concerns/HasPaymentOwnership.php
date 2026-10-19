<?php

declare(strict_types=1);

namespace Nvl\Payments\Models\Concerns;

use Nvl\Payments\Compatibility\LegacyUnownedPaymentScope;
use Nvl\Support\Ownership\Services\OwnershipScope;
use Nvl\Support\Ownership\Traits\HasOwnership;

/** Adds canonical native protection while retaining the explicitly guarded historical global Payments boundary. @internal */
trait HasPaymentOwnership
{
    use HasOwnership {
        save as private saveNativeOwnership;
        delete as private deleteNativeOwnership;
        incrementOrDecrement as private incrementNativeOwnership;
    }

    /** Preserve historical explicitly guarded writes only before native adoption.
     * @param  array<string,mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return LegacyUnownedPaymentScope::permitsLegacy($this) ? parent::save($options) : $this->saveNativeOwnership($options);
    }

    /** Keep native markers effective when a legacy configuration is selected after adoption. */
    public function delete(): ?bool
    {
        return LegacyUnownedPaymentScope::permitsLegacy($this) ? parent::delete() : $this->deleteNativeOwnership();
    }

    /** Preserve the legacy native-counter boundary only for unadopted storage.
     * @param  array<string,mixed>  $extra
     */
    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): int|bool
    {
        return LegacyUnownedPaymentScope::permitsLegacy($this)
            ? parent::incrementOrDecrement($column, $amount, $extra, $method)
            : $this->incrementNativeOwnership($column, $amount, $extra, $method);
    }

    /** Keep the standard scope key so the supported framework restoration seams retain their identity. */
    public static function bootHasOwnership(): void
    {
        static::addGlobalScope(OwnershipScope::class, new LegacyUnownedPaymentScope);
    }
}
