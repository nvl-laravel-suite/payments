<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Services\PaymentReadService;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Exceptions\BindingRequiredException;

require_once __DIR__.'/../ReconciliationTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    setupRefundHost();
});

it('uses persisted Eloquent actor permissions for native payment reads', function (): void {
    Schema::create('payments_test_actors', function (Blueprint $table): void {
        $table->id();
        $table->boolean('may_manage');
    });
    $actor = new class extends User
    {
        protected $table = 'payments_test_actors';

        protected $guarded = [];

        public $timestamps = false;
    };
    $actor->fill(['may_manage' => false])->save();
    $actor->setAttribute('may_manage', true);
    app()->instance(PaymentManagementAccess::class, new class implements PaymentManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void
        {
            if (! $actor instanceof User || ! $actor->getAttribute('may_manage')) {
                throw new AuthorizationException;
            }
        }
    });
    expect(fn () => app(PaymentReadService::class)->forOrder('order-1', $actor))->toThrow(AuthorizationException::class);
});

it('denies reading by default', function (): void {
    app()->bind(PaymentManagementAccess::class, DenyPaymentManagementAccess::class);
    expect(fn () => app(PaymentReadService::class)->forOrder('order-1', new GenericUser(['id' => 'admin'])))->toThrow(BindingRequiredException::class);
});

it('reads conservative balances using a fixed number of queries', function (int $count): void {
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->once()->withArgs(fn ($actor, $kind, $order) => $kind === 'view' && $order->reference === 'order-1');
    app()->instance(PaymentManagementAccess::class, $access);
    for ($i = 0; $i < $count; $i++) {
        $attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'attached', 'state' => 'captured', 'captured_amount_minor' => 1200]);
        reconciliationRefund($attempt, 're_'.$i, 'succeeded', 200);
        reconciliationRefund($attempt, null, 'reserved', 300);
    }
    DB::connection()->enableQueryLog();
    $timeline = app(PaymentReadService::class)->forOrder('order-1', new GenericUser(['id' => 'admin']));
    $queries = collect(DB::connection()->getQueryLog());
    $dataQueries = $queries->filter(fn (array $query): bool => str_starts_with(str_replace(['"', '`'], '', $query['query']), 'select * from nvl_payments_'));
    expect($dataQueries)->toHaveCount(2)->and($queries->count() - $dataQueries->count())->toBe(0)->and($timeline['payments'])->toHaveCount($count)
        ->and($timeline['payments'][0]['refundedAmountMinor'])->toBe(200)->and($timeline['payments'][0]['refundableAmountMinor'])->toBe(700)
        ->and($timeline['payments'][0]['reservedRefundAmountMinor'])->toBe(300)->and($timeline['refunds'])->toHaveCount($count * 2);
})->with([1, 15]);

it('reads only the configured Payments connection', function (): void {
    config(['database.connections.payments_secondary' => ['driver' => 'sqlite', 'database' => ':memory:'], 'nvl-payments.connection' => 'payments_secondary']);
    $migration = require __DIR__.'/../../database/migrations/payments/2026_09_28_000001_nvl_payments_create_payments_tables.php';
    $migration->up();
    $attempt = createRefundAttempt();
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    $timeline = app(PaymentReadService::class)->forOrder('order-1', new GenericUser(['id' => 'admin']));
    expect($timeline['payments'])->toHaveCount(1)->and($timeline['payments'][0]['attemptId'])->toBe($attempt->id)
        ->and(DB::connection(config('database.default'))->table(PaymentAttempt::TABLE)->count())->toBe(0);
});
