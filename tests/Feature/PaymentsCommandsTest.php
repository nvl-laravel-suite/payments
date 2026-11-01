<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;

require_once __DIR__.'/../ReconciliationTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    $this->withoutMockingConsoleOutput();
});

it('reports readiness without printing secrets', function (): void {
    config(['nvl-payments.stripe.secret' => 'sk_test_do_not_print', 'nvl-payments.stripe.webhook_secret' => 'whsec_do_not_print']);
    $code = Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']);
    $output = Artisan::output();
    expect($code)->toBe(1)->and($output)->not->toContain('sk_test_do_not_print', 'whsec_do_not_print');
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toHaveKey('checks');
});

it('reconciles a target and a paginated sweep', function (): void {
    config(['nvl-payments.stripe.account_id' => 'acct_test', 'nvl-payments.reconciliation.batch_size' => 1]);
    $attempt = createRefundAttempt();
    PaymentAttempt::create(['order_reference' => 'other', 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'reserved']);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('payment')->twice()->andReturn(refundPaymentState());
    $gateway->shouldReceive('refunds')->twice()->andReturn([]);
    app()->instance(PaymentGateway::class, $gateway);
    expect(Artisan::call('nvl:payments:reconcile', ['--payment' => $attempt->id]))->toBe(0);
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(0)->and(Artisan::output())->toContain('2');
});

it('limits a sweep and reads IDs in configured database pages', function (): void {
    config(['nvl-payments.reconciliation.batch_size' => 1, 'nvl-payments.reconciliation.max_attempts' => 2]);
    for ($i = 0; $i < 4; $i++) {
        PaymentAttempt::create(['order_reference' => 'order-'.$i, 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'reserved']);
    }
    app()->instance(PaymentGateway::class, Mockery::mock(PaymentGateway::class));
    $connection = (new PaymentAttempt)->getConnection();
    $connection->enableQueryLog();
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(0)->and(Artisan::output())->toContain('2 reconciled');
    $selectId = 'select '.$connection->getQueryGrammar()->wrap('id');
    $pages = collect($connection->getQueryLog())->filter(fn ($query) => str_contains($query['query'], $selectId));
    expect($pages)->toHaveCount(2);
    foreach ($pages as $page) {
        expect($page['query'])->toContain('limit 1');
    }
});

it('sanitizes failed reconciliation output and continues the sweep', function (): void {
    config(['nvl-payments.stripe.account_id' => 'acct_test']);
    createRefundAttempt();
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('payment')->andThrow(new RuntimeException('sk_test_secret whsec_secret'));
    app()->instance(PaymentGateway::class, $gateway);
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(1)->and(Artisan::output())->not->toContain('sk_test_secret', 'whsec_secret');
});

it('reports a ready local installation in strict mode', function (): void {
    setupRefundHost();
    config(['nvl-payments.stripe.secret' => 'sk_test_private', 'nvl-payments.stripe.webhook_secret' => 'whsec_private', 'nvl-payments.allowed_currencies' => ['EUR'], 'nvl-payments.checkout.return_hosts' => ['shop.test']]);
    Route::getRoutes()->getByName('nvl.payments.webhook')->name('consumer.payments.webhook');
    Route::getRoutes()->refreshNameLookups();
    expect(Route::has('nvl.payments.webhook'))->toBeFalse();
    expect(Artisan::call('nvl:payments:doctor', ['--strict' => true]))->toBe(0);
    $output = Artisan::output();
    expect($output)->not->toContain('webhook_route', 'sk_test_private', 'whsec_private');
});

it('rejects a malformed target without printing it', function (): void {
    app()->instance(PaymentGateway::class, Mockery::mock(PaymentGateway::class));
    expect(Artisan::call('nvl:payments:reconcile', ['--payment' => 'sk_test_private']))->toBe(1)->and(Artisan::output())->not->toContain('sk_test_private');
});

it('advances bounded sweeps past permanently unresolved attempts', function (): void {
    config(['nvl-payments.stripe.account_id' => 'acct_test', 'nvl-payments.reconciliation.batch_size' => 1, 'nvl-payments.reconciliation.max_attempts' => 2]);
    $blocked = [];
    for ($i = 0; $i < 3; $i++) {
        $blocked[] = PaymentAttempt::create(['order_reference' => 'blocked-'.$i, 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'reserved', 'reservation_key' => 'blocked-'.$i]);
    }
    $repairable = createRefundAttempt();
    $repairable->update(['last_synced_at' => now()->subDay()]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('payment')->once()->andReturn(refundPaymentState(700));
    $gateway->shouldReceive('refunds')->once()->andReturn([remoteRefund()]);
    app()->instance(PaymentGateway::class, $gateway);
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(0);
    expect($repairable->refresh()->refunded_amount_minor)->toBe(0);
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(0);
    expect($repairable->refresh()->refunded_amount_minor)->toBe(700);
    foreach ($blocked as $attempt) {
        expect($attempt->refresh()->reservation_key)->not->toBeNull()->and($attempt->last_synced_at)->toBeNull()
            ->and($attempt->last_reconcile_attempt_at)->not->toBeNull();
    }
});

describe('local ledger readiness', function (): void {
    beforeEach(function (): void {
        setupRefundHost();
        config([
            'nvl-payments.stripe.secret' => 'sk_ledger_private',
            'nvl-payments.stripe.webhook_secret' => 'whsec_ledger_private',
            'nvl-payments.allowed_currencies' => ['EUR'],
            'nvl-payments.checkout.return_hosts' => ['shop.test'],
        ]);
    });

    it('rejects corrupt persisted attempt amounts without disclosing ledger facts', function (array $amounts): void {
        $attempt = createRefundAttempt();
        $attempt->forceFill($amounts)->save();

        expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
        $output = Artisan::output();
        $checks = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['checks'];

        expect($checks['financial_attempt_amounts'])->toBeFalse()
            ->and($output)->not->toContain($attempt->id, 'order-1', 'sk_ledger_private', 'whsec_ledger_private');
    })->with([
        'zero total' => [['amount_minor' => 0, 'captured_amount_minor' => 0, 'refunded_amount_minor' => 0]],
        'capture exceeds original total' => [['captured_amount_minor' => 1201]],
        'refund exceeds captured total' => [['refunded_amount_minor' => 1201]],
    ]);

    it('rejects an out of bounds persisted refund amount', function (int $amount): void {
        reconciliationRefund(createRefundAttempt(), null, 'reserved', $amount);

        expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks']['financial_refund_amounts'])->toBeFalse();
    })->with(['zero' => [0], 'exceeds original payment' => [1201]]);

    it('rejects aggregate live refund reservations above the original payment', function (): void {
        $attempt = createRefundAttempt();
        reconciliationRefund($attempt, null, 'reserved', 700);
        reconciliationRefund($attempt, null, 'pending', 700);

        expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks']['financial_refund_reservations'])->toBeFalse();
    });

    it('accepts valid balances while excluding released refund reservations', function (): void {
        $attempt = createRefundAttempt();
        $attempt->update(['refunded_amount_minor' => 700]);
        reconciliationRefund($attempt, 're_pending', 'pending', 700);
        reconciliationRefund($attempt, 're_failed', 'failed', 700);
        reconciliationRefund($attempt, 're_canceled', 'canceled', 700);

        expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(0);
        $checks = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'];
        expect($checks['financial_attempt_amounts'])->toBeTrue()
            ->and($checks['financial_refund_amounts'])->toBeTrue()
            ->and($checks['financial_refund_reservations'])->toBeTrue();
    });

    it('fails closed before probing an incomplete ledger schema', function (): void {
        $connection = (new PaymentAttempt)->getConnection();
        $connection->getSchemaBuilder()->table(PaymentsTables::get(PaymentsTables::Attempts), function (Blueprint $table): void {
            $table->dropColumn('refunded_amount_minor');
        });

        expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks']['financial_attempt_amounts'])->toBeFalse();
    });

    it('fails closed without exposing a ledger inspection source error', function (): void {
        $connection = (new PaymentAttempt)->getConnection();
        $connection->beforeExecuting(function (string $query): void {
            if (str_contains(strtolower($query), 'select exists(')) {
                throw new PDOException('private-ledger-source-error');
            }
        });

        expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
        $output = Artisan::output();
        $checks = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['checks'];
        expect($checks['schema_connection'])->toBeFalse()
            ->and($checks['financial_attempt_amounts'])->toBeFalse()
            ->and($output)->not->toContain('private-ledger-source-error');
    });

    it('inspects the configured writer rather than a lagging or corrupt replica', function (): void {
        $attempt = createRefundAttempt();
        $connection = $attempt->getConnection();

        if ($connection->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The adversarial read/write fixture uses two independent SQLite stores; native ledger checks run in the other cases.');
        }

        expect($connection->transactionLevel())->toBe(0);
        config(['database.connections.payments_doctor_replica' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        $replica = DB::connection('payments_doctor_replica');
        $default = config('database.default');
        $originalReadPdo = $connection->getReadPdo();
        $rawAttempt = $attempt->getAttributes();

        try {
            config(['database.default' => 'payments_doctor_replica']);
            try {
                (require __DIR__.'/../../database/migrations/payments/2026_09_28_000001_nvl_payments_create_payments_tables.php')->up();
            } finally {
                config(['database.default' => $default]);
            }

            $attempt->update(['refunded_amount_minor' => 1201]);
            $connection->setReadPdo($replica->getPdo());
            expect($connection->table(PaymentsTables::get(PaymentsTables::Attempts))->exists())->toBeFalse();
            expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(1);
            expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks']['financial_attempt_amounts'])->toBeFalse();

            $connection->table(PaymentsTables::get(PaymentsTables::Attempts))->where('id', $attempt->id)->update(['refunded_amount_minor' => 0]);
            $replica->table(PaymentsTables::get(PaymentsTables::Attempts))->insert([...$rawAttempt, 'refunded_amount_minor' => 1201]);
            expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(0);
            expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks']['financial_attempt_amounts'])->toBeTrue();

            foreach ([PaymentsTables::WebhookEvents, PaymentsTables::Refunds, PaymentsTables::Operations, PaymentsTables::Attempts] as $table) {
                $replica->getSchemaBuilder()->drop(PaymentsTables::get($table));
            }
            expect(Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']))->toBe(0);
            expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks']['schema_'.PaymentsTables::get(PaymentsTables::Attempts)])->toBeTrue();
        } finally {
            $connection->setReadPdo($originalReadPdo);
            DB::purge('payments_doctor_replica');
        }
    });
});
