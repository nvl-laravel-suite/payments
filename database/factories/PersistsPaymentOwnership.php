<?php

declare(strict_types=1);

namespace Nvl\Payments\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Keeps native Payment factory persistence inside its exact source assignment.
 * @template TModel of Model
 *
 * @internal
 */
trait PersistsPaymentOwnership
{
    /** @param Collection<int, TModel> $results */
    protected function store(Collection $results): void
    {
        if (Container::getInstance()->make(OwnershipResourceRegistry::class)->get('payments.attempts')->ownership->strategy === 'unscoped') {
            parent::store($results);

            return;
        }
        foreach ($results as $model) {
            Container::getInstance()->make(OwnershipWriter::class)->create($model::class, function (array $attributes) use ($model): Model {
                $model->forceFill($attributes);
                parent::store(new Collection([$model]));

                return $model;
            });
        }
    }
}
