<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Providers\PaymentsServiceProvider;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;

uses(PaymentsSchemaTestCase::class);

it('creates four UUID-backed tables with the expected payment references', function (): void {
    foreach ([PaymentsTables::Attempts, PaymentsTables::Operations, PaymentsTables::Refunds, PaymentsTables::WebhookEvents] as $name) {
        expect(Schema::hasTable($name))->toBeTrue()
            ->and(Schema::hasColumn($name, 'id'))->toBeTrue();
    }

    expect(Schema::hasColumns(PaymentsTables::Attempts, [
        'reservation_key', 'stripe_checkout_session_id', 'stripe_payment_intent_id', 'stripe_charge_id',
    ]))->toBeTrue();
});

it('allows multiple unreserved attempts but rejects a duplicate reservation', function (): void {
    DB::table(PaymentsTables::Attempts)->insert([
        'id' => '11111111-1111-4111-8111-111111111111',
        'order_reference' => 'order-1', 'order_revision' => '1', 'amount_minor' => 100,
        'currency' => 'USD', 'origin' => 'checkout', 'state' => 'reserved',
    ]);
    DB::table(PaymentsTables::Attempts)->insert([
        'id' => '22222222-2222-4222-8222-222222222222',
        'order_reference' => 'order-1', 'order_revision' => '1', 'amount_minor' => 100,
        'currency' => 'USD', 'origin' => 'checkout', 'state' => 'reserved',
    ]);
    DB::table(PaymentsTables::Attempts)->where('id', '11111111-1111-4111-8111-111111111111')->update(['reservation_key' => 'order-1']);

    expect(fn () => DB::table(PaymentsTables::Attempts)->where('id', '22222222-2222-4222-8222-222222222222')->update(['reservation_key' => 'order-1']))
        ->toThrow(QueryException::class);
});

it('publishes a migration source for host-owned migration mode', function (): void {
    $paths = array_keys(PaymentsServiceProvider::pathsToPublish(
        PaymentsServiceProvider::class,
        'payments-migrations',
    ));

    expect($paths)->toHaveCount(1)
        ->and(is_dir($paths[0]))->toBeTrue();
});

it('uniquely indexes Stripe references and durable operation identities', function (): void {
    $expected = [
        PaymentsTables::Attempts => [
            'reservation_key', 'stripe_checkout_session_id', 'stripe_payment_intent_id', 'stripe_charge_id',
        ],
        PaymentsTables::Operations => ['idempotency_key'],
        PaymentsTables::Refunds => ['stripe_refund_id', 'payment_operation_id'],
        PaymentsTables::WebhookEvents => ['stripe_event_id'],
    ];

    foreach ($expected as $table => $columns) {
        $indexed = collect(Schema::getIndexes($table))
            ->filter(static fn (array $index): bool => $index['unique'])
            ->flatMap(static fn (array $index): array => $index['columns'])
            ->all();

        foreach ($columns as $column) {
            expect($indexed)->toContain($column);
        }
    }
});
