<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Ownership\PaymentsIntegrityGuard;
use Nvl\Payments\Services\PaymentOperationJournal;
use Nvl\Payments\Tests\OwnerScopeTestCase;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

uses(OwnerScopeTestCase::class, OptsIntoAdoptedSchemaReuse::class);

it('rejects raw cross-owner operation links and provider key reassignment even for equal host order references', function (): void {
    $create = fn () => PaymentAttempt::query()->create(['order_reference' => 'equal-order', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'open']);
    $foreign = app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), $create);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($create, $foreign): void {
        $own = $create();
        $operation = app(PaymentOperationJournal::class)->reserve('refund', 'equal-order', (string) Str::uuid(), '1', 'immutable-input', 200);
        $query = fn () => $operation->getConnection()->table($operation->getTable())->where('id', $operation->id);
        expect(fn () => $query()->update(['payment_attempt_id' => $foreign->id]))->toThrow(QueryException::class);
        expect($query()->update(['payment_attempt_id' => $own->id]))->toBe(1);
        expect(fn () => $query()->update(['idempotency_key' => 'replacement']))->toThrow(QueryException::class);
        expect($operation->fresh()->payment_attempt_id)->toBe($own->id);
    });
});

it('freezes settled charge identity while permitting pre-settlement replacement and first assignment', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        $make = fn (string $order, string $state, ?string $charge) => PaymentAttempt::query()->create(['order_reference' => $order, 'order_revision' => 'v1', 'amount_minor' => 1000,
            'currency' => 'EUR', 'origin' => 'checkout', 'state' => $state, 'stripe_charge_id' => $charge, 'captured_amount_minor' => $state === 'captured' ? 1000 : 0]);
        $pending = $make('pending', 'open', 'ch_first');
        expect($pending->getConnection()->table($pending->getTable())->where('id', $pending->id)->update(['stripe_charge_id' => 'ch_retry']))->toBe(1);
        $settled = $make('settled', 'captured', 'ch_settled');
        expect(fn () => $settled->getConnection()->table($settled->getTable())->where('id', $settled->id)->update(['stripe_charge_id' => 'ch_reassigned']))->toThrow(QueryException::class);
        $late = $make('late-charge', 'captured', null);
        expect($late->getConnection()->table($late->getTable())->where('id', $late->id)->update(['stripe_charge_id' => 'ch_late']))->toBe(1);
        expect(fn () => $late->getConnection()->table($late->getTable())->where('id', $late->id)->update(['stripe_charge_id' => 'ch_late_reassigned']))->toThrow(QueryException::class);
    });
});

it('denies financial effects without complete live provider correlation uniqueness', function (bool $incomplete): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($incomplete): void {
        $model = new PaymentAttempt;
        $schema = $model->getConnection()->getSchemaBuilder();
        $name = null;
        foreach ($schema->getIndexes($model->getTable()) as $index) {
            if ($index['unique'] && $index['columns'] === ['stripe_payment_intent_id']) {
                $name = $index['name'];
            }
        }
        expect($name)->toBeString();
        $schema->table($model->getTable(), fn (Blueprint $table) => $table->dropUnique($name));
        if ($incomplete) {
            $grammar = $model->getConnection()->getQueryGrammar();
            $coverage = '('.$grammar->wrap('stripe_payment_intent_id').') WHERE 1 = 0';
            $model->getConnection()->statement('CREATE UNIQUE INDEX '.$grammar->wrap($name).' ON '.$grammar->wrapTable($model->getTable()).' '.$coverage);
        }
        expect(fn () => app(PaymentsIntegrityGuard::class)->assertReady())
            ->toThrow(OwnershipSchemaNotReady::class);
    });
})->group('fresh-adoption')->with(['missing' => false, 'incomplete' => true]);
