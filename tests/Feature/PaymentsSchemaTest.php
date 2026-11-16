<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Providers\PaymentsServiceProvider;
use Nvl\Payments\Services\StripePaymentGateway;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;

uses(PaymentsSchemaTestCase::class);

it('resolves the default gateway from canonical options while preserving foreign payment configuration', function (): void {
    $foreign = ['stripe' => ['secret' => ['host-owned'], 'account_id' => null, 'livemode' => 'foreign']];
    config([
        'payments' => $foreign,
        'nvl-payments.stripe.secret' => 'sk_test_nvl_fixture',
        'nvl-payments.stripe.account_id' => 'acct_nvl_fixture',
        'nvl-payments.stripe.livemode' => false,
    ]);

    expect(app(PaymentGateway::class))->toBeInstanceOf(StripePaymentGateway::class)
        ->and(config('payments'))->toBe($foreign);
});

it('creates four UUID-backed tables with the expected payment references', function (): void {
    foreach ([PaymentsTables::Attempts, PaymentsTables::Operations, PaymentsTables::Refunds, PaymentsTables::WebhookEvents] as $name) {
        expect(Schema::hasTable($name))->toBeTrue()
            ->and(Schema::hasColumn($name, 'id'))->toBeTrue();
    }

    expect(Schema::hasColumns(PaymentsTables::Attempts, [
        'reservation_key', 'stripe_checkout_session_id', 'stripe_payment_intent_id', 'stripe_charge_id',
    ]))->toBeTrue();
});

it('guards every financial table against migration rollback by default', function (): void {
    $migration = require __DIR__.'/../../database/migrations/payments/2026_09_28_000001_nvl_payments_create_payments_tables.php';
    $attempts = PaymentsTables::get(PaymentsTables::Attempts);
    $attempt = '99999999-9999-4999-8999-999999999999';
    DB::table($attempts)->insert([
        'id' => $attempt, 'order_reference' => 'retained-order', 'order_revision' => '1',
        'amount_minor' => 100, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'reserved',
    ]);

    config()->set('nvl-payments.migrations.allow_destructive_rollback', false);
    try {
        expect(fn () => $migration->down())->toThrow(LogicException::class, 'allow_destructive_rollback');
        foreach ([PaymentsTables::Attempts, PaymentsTables::Operations, PaymentsTables::Refunds, PaymentsTables::WebhookEvents] as $name) {
            expect(Schema::hasTable(PaymentsTables::get($name)))->toBeTrue();
        }
        expect(DB::table($attempts)->where('id', $attempt)->exists())->toBeTrue();
    } finally {
        config()->set('nvl-payments.migrations.allow_destructive_rollback', true);
    }
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
        'nvl-payments-migrations',
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
