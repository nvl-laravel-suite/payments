<?php

declare(strict_types=1);

namespace Nvl\Payments\Ownership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipDatabaseConstraints;
use Nvl\Ownership\Services\OwnershipResourceBackfill;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Adopts exact reviewed Payments records without guessing primary owners from associations.
 *
 * @internal
 */
final readonly class PaymentsAdoptionAdapter implements OwnershipAdoptionAdapter
{
    /** Retain the canonical services and immutable inputs for this workflow. */
    public function __construct(
        private OwnershipResourceRegistry $resources,
        private OwnershipAdoptionStore $mappings,
        private OwnershipSchema $schema,
        private OwnershipDatabaseConstraints $constraints,
        private OwnershipConstraintInspector $indexes,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['payments.attempts', 'payments.operations', 'payments.refunds', 'payments.webhooks'];
    }

    /** Prepare additive partitions and replace only the package's known global receipt identity. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $model = new ($this->resources->get($key)->model);
            $schema = $model->getConnection()->getSchemaBuilder();
            foreach (['tenant_id', 'ownership_key'] as $column) {
                if ($schema->hasColumn($model->getTable(), $column)) {
                    $schema->table($model->getTable(), static fn (Blueprint $table) => ($column === 'tenant_id' ? $table->uuid($column) : $table->string($column, 191))->nullable()->change());
                }
            }
            $this->schema->prepare($key);
        }
        $this->restoreNativeParents();
    }

    /** Restore the concrete native FK when a legacy adapter replaced it with a tenant composite.
     * @param  list<array{string,string}>  $extraEdges
     */
    private function restoreNativeParents(array $extraEdges = []): void
    {
        foreach ($this->resources() as $key) {
            $definition = $this->resources->get($key);
            if ($definition->ownership->strategy !== 'inherited' || count($definition->parents()) !== 1) {
                continue;
            }
            $model = new $definition->model;
            $relationName = $definition->ownership->relation ?? throw new OwnershipConfigurationInvalid('Inherited ownership requires a concrete parent relation.');
            $relation = $model->{$relationName}();
            if (! $relation instanceof BelongsTo) {
                throw new OwnershipConfigurationInvalid('Inherited ownership requires a canonical native parent relation.');
            }
            if ($relation instanceof MorphTo) {
                continue;
            }
            $parent = new ($this->resources->get($definition->parents()[0])->model);
            $this->restoreNativeForeign($model, $relation->getForeignKeyName(), $parent);
        }
        foreach ($extraEdges as [$resource, $column]) {
            $model = new ($this->resources->get($resource)->model);
            $this->restoreNativeForeign($model, $column, $model);
        }
    }

    /** Preserve existing keys and add only a missing exact native parent constraint. */
    private function restoreNativeForeign(Model $model, string $column, Model $parent): void
    {
        if ($model->getConnection() !== $parent->getConnection()) {
            throw new OwnershipConfigurationInvalid('Inherited package records require the same actual native source.');
        }
        $schema = $model->getConnection()->getSchemaBuilder();
        foreach ($schema->getForeignKeys($model->getTable()) as $foreign) {
            if ($foreign['columns'] === [$column]
                && $foreign['foreign_table'] === $parent->getConnection()->getTablePrefix().$parent->getTable()
                && $foreign['foreign_columns'] === [$parent->getKeyName()]) {
                return;
            }
        }
        $name = 'nvl_native_'.substr(hash('sha256', $model->getTable().$column), 0, 24);
        $schema->table($model->getTable(), static fn (Blueprint $table) => $table->foreign([$column], $name)->references([$parent->getKeyName()])->on($parent->getTable())->restrictOnDelete());
    }

    /** Backfill bounded actual records from explicit immutable per-record mappings. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        return (new OwnershipResourceBackfill($this->resources, $this->mappings))->run($plan, $this->resources(), $cursor, $limit, 'Payments');
    }

    /** Verify persisted partitions and the complete canonical domain graph. */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        foreach ($this->resources() as $key) {
            $this->schema->verify($key);
        }

        foreach (PaymentsIntegrityGuard::Unique as $key => $sets) {
            $model = new ($this->resources->get($key)->model);
            $this->indexes->assertUniqueIndexes($model->getConnection(), $model->getTable(), $sets);
        }
        $attempt = new ($this->resources->get('payments.attempts')->model);
        $operation = new ($this->resources->get('payments.operations')->model);
        $refund = new ($this->resources->get('payments.refunds')->model);
        $connection = $attempt->getConnection();
        if ($connection !== $operation->getConnection() || $connection !== $refund->getConnection()) {
            throw new OwnershipConfigurationInvalid('Native financial references require one actual source.');
        }
        $invalidOperation = $connection->table($operation->getTable().' as operation')->leftJoin($attempt->getTable().' as attempt', 'attempt.id', '=', 'operation.payment_attempt_id')
            ->whereNotNull('operation.payment_attempt_id')->where(static fn (Builder $query): Builder => $query->whereNull('attempt.id')->orWhereColumn('operation.owner_scope_key', '<>', 'attempt.owner_scope_key')->orWhereColumn('operation.order_reference', '<>', 'attempt.order_reference'))->exists();
        $invalidRefund = $connection->table($refund->getTable().' as refund')->leftJoin($operation->getTable().' as operation', 'operation.id', '=', 'refund.payment_operation_id')
            ->where(static fn (Builder $query): Builder => $query->whereNull('operation.id')->orWhereColumn('refund.owner_scope_key', '<>', 'operation.owner_scope_key')->orWhereColumn('refund.payment_attempt_id', '<>', 'operation.payment_attempt_id')->orWhereNull('operation.payment_attempt_id'))->exists();

        return new OwnershipVerification($invalidOperation || $invalidRefund || ! $this->providerMappingsMatch() ? ['Financial references differ from their canonical owner or payment.'] : []);
    }

    /** Require deliberate terminal global history or the exact persisted source payment owner. */
    private function providerMappingsMatch(): bool
    {
        $webhook = new ($this->resources->get('payments.webhooks')->model);
        $attempt = new ($this->resources->get('payments.attempts')->model);
        if ($webhook->getConnection() !== $attempt->getConnection()) {
            return false;
        }
        foreach ($webhook->getConnection()->table($webhook->getTable())->useWritePdo()->select(['id', 'type', 'stripe_object_id', 'owner_scope_key', 'status'])->orderBy('id')->lazyById(100) as $row) {
            if ($row->owner_scope_key === 'central') {
                if (! in_array($row->status, ['processed', 'ignored'], true)) {
                    return false;
                }

                continue;
            }
            if (! is_string($row->type) || ! is_string($row->stripe_object_id)) {
                return false;
            }
            $field = PaymentProviderCorrelation::field($row->type);
            if ($field === null) {
                return false;
            }
            $sources = $attempt->getConnection()->table($attempt->getTable())->useWritePdo()->where($field, $row->stripe_object_id)->limit(2)->get(['owner_scope_key']);
            if ($sources->count() !== 1 || $sources->first()?->owner_scope_key !== $row->owner_scope_key) {
                return false;
            }
        }

        return true;
    }

    /** Constrain every native parent and scope edge before activation. */
    public function activate(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $this->schema->constrain($key);
        }
        foreach (PaymentsIntegrityGuard::Assigned as $key => $fields) {
            $model = new ($this->resources->get($key)->model);
            $this->constraints->freezeAssigned($model->getConnection(), $model->getTable(), $fields);
        }
        $attempt = new ($this->resources->get('payments.attempts')->model);
        $operation = new ($this->resources->get('payments.operations')->model);
        $refund = new ($this->resources->get('payments.refunds')->model);
        $this->constraints->freezeAssigned($attempt->getConnection(), $attempt->getTable(), ['stripe_charge_id'], 'state', PaymentsIntegrityGuard::SettledChargeStates);
        $this->constraints->reference($operation->getConnection(), $operation->getTable(), 'payment_attempt_id', $attempt->getTable(), 'id', nullable: true, sameColumns: ['order_reference']);
        $this->constraints->reference($refund->getConnection(), $refund->getTable(), 'payment_operation_id', $operation->getTable(), 'id', sameColumns: ['payment_attempt_id']);
    }
}
