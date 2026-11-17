<?php

declare(strict_types=1);

namespace Nvl\Payments\Support;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Stores payment instants as UTC seconds independently of the host timezone.
 *
 * A local wall clock is ambiguous inside a daylight-saving overlap, so storing one
 * would lose the provider's instant. A string without an offset is read in the host
 * timezone, matching Laravel's own date parsing.
 *
 * @implements CastsAttributes<Carbon, DateTimeInterface|string>
 *
 * @internal
 */
final class PaymentInstantCast implements CastsAttributes
{
    /** Format an instant for storage, including query-builder writes that bypass model casts. */
    public static function store(DateTimeInterface $instant): string
    {
        return Carbon::instance($instant)->utc()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Payment timestamps require UTC strings.');
        }

        return Carbon::parse($value, 'UTC');
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        return [$key => self::store($value instanceof DateTimeInterface ? $value : Carbon::parse($value))];
    }
}
