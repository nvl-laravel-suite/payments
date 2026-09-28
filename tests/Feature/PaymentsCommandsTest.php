<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;

require_once __DIR__.'/../ReconciliationTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    $this->withoutMockingConsoleOutput();
});

it('reports readiness without printing secrets', function (): void {
    config(['payments.stripe.secret' => 'sk_test_do_not_print', 'payments.stripe.webhook_secret' => 'whsec_do_not_print']);
    $code = Artisan::call('nvl:payments:doctor', ['--strict' => true, '--format' => 'json']);
    $output = Artisan::output();
    expect($code)->toBe(1)->and($output)->not->toContain('sk_test_do_not_print', 'whsec_do_not_print');
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toHaveKey('checks');
});

it('reconciles a target and a paginated sweep', function (): void {
    config(['payments.stripe.account_id' => 'acct_test', 'payments.reconciliation.batch_size' => 1]);
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
    config(['payments.reconciliation.batch_size' => 1, 'payments.reconciliation.max_attempts' => 2]);
    for ($i = 0; $i < 4; $i++) {
        PaymentAttempt::create(['order_reference' => 'order-'.$i, 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'reserved']);
    }
    app()->instance(PaymentGateway::class, Mockery::mock(PaymentGateway::class));
    $connection = (new PaymentAttempt)->getConnection();
    $connection->enableQueryLog();
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(0)->and(Artisan::output())->toContain('2 reconciled');
    $pages = collect($connection->getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'select "id"'));
    expect($pages)->toHaveCount(2);
    foreach ($pages as $page) {
        expect($page['query'])->toContain('limit 1');
    }
});

it('sanitizes failed reconciliation output and continues the sweep', function (): void {
    config(['payments.stripe.account_id' => 'acct_test']);
    createRefundAttempt();
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('payment')->andThrow(new RuntimeException('sk_test_secret whsec_secret'));
    app()->instance(PaymentGateway::class, $gateway);
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(1)->and(Artisan::output())->not->toContain('sk_test_secret', 'whsec_secret');
});

it('reports a ready local installation in strict mode', function (): void {
    setupRefundHost();
    config(['payments.stripe.secret' => 'sk_test_private', 'payments.stripe.webhook_secret' => 'whsec_private', 'payments.allowed_currencies' => ['EUR'], 'payments.checkout.return_hosts' => ['shop.test']]);
    expect(Artisan::call('nvl:payments:doctor', ['--strict' => true]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('webhook_route: ready')->not->toContain('sk_test_private', 'whsec_private');
});

it('rejects a malformed target without printing it', function (): void {
    app()->instance(PaymentGateway::class, Mockery::mock(PaymentGateway::class));
    expect(Artisan::call('nvl:payments:reconcile', ['--payment' => 'sk_test_private']))->toBe(1)->and(Artisan::output())->not->toContain('sk_test_private');
});

it('advances bounded sweeps past permanently unresolved attempts', function (): void {
    config(['payments.stripe.account_id' => 'acct_test', 'payments.reconciliation.batch_size' => 1, 'payments.reconciliation.max_attempts' => 2]);
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
