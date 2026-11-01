<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Services\PaymentReadService;
use Nvl\Payments\Services\PaymentRefunds;
use Nvl\Payments\Services\PaymentsDoctor;
use Nvl\Payments\Services\PaymentsModelRegistry;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

class ConfiguredPaymentAttemptFixture extends PaymentAttempt {}
class IncompatibleAttemptCasts extends PaymentAttempt
{
    protected function casts(): array
    {
        return [...parent::casts(), 'amount_minor' => 'string'];
    }
}
class IncompatibleRefundHook extends PaymentRefund
{
    public function newModelQuery(): Builder
    {
        return parent::newModelQuery();
    }
}
class SplitRefundSource extends PaymentRefund
{
    protected $connection = 'financial_other';
}
class IncompatibleAttemptKey extends PaymentAttempt
{
    protected $primaryKey = 'alternate_id';
}

class ConfiguredPaymentRefundFixture extends PaymentRefund {}

class ConfiguredPaymentsModelsTestCase extends PaymentsSchemaTestCase
{
    protected function setUp(): void
    {
        Relation::morphMap([], false);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Relation::morphMap([], false);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set([
            'database.connections.financial_source' => $app['config']->get('database.connections.'.$app['config']->get('database.default')),
            'nvl-payments.connection' => 'financial_source',
            'nvl-payments.tables.attempts' => 'custom_payment_attempts',
            'nvl-payments.tables.refunds' => 'custom_payment_refunds',
            'nvl-payments.models.attempt' => ConfiguredPaymentAttemptFixture::class,
            'nvl-payments.models.refund' => ConfiguredPaymentRefundFixture::class,
        ]);
    }
}

uses(ConfiguredPaymentsModelsTestCase::class);

it('creates configured payment and refund roots through the shipped factories', function (): void {
    $attempt = PaymentAttempt::factory()->create(['captured_amount_minor' => 1000]);
    expect($attempt)->toBeInstanceOf(ConfiguredPaymentAttemptFixture::class)
        ->and($attempt->getConnection()->getName())->toBe('financial_source')
        ->and($attempt->getTable())->toBe('custom_payment_attempts');
    $refund = PaymentRefund::factory()->create();
    expect($refund)->toBeInstanceOf(ConfiguredPaymentRefundFixture::class)
        ->and($refund->paymentAttempt)->toBeInstanceOf(ConfiguredPaymentAttemptFixture::class);
});

it('inspects monetary bounds on the configured financial source and table', function (): void {
    $attempt = PaymentAttempt::factory()->create(['amount_minor' => 1200, 'captured_amount_minor' => 1200, 'refunded_amount_minor' => 0]);
    $checks = app(PaymentsDoctor::class)->inspect();

    expect($checks['schema_custom_payment_attempts'])->toBeTrue()
        ->and($checks['schema_custom_payment_refunds'])->toBeTrue()
        ->and($checks['financial_attempt_amounts'])->toBeTrue();

    $attempt->update(['refunded_amount_minor' => 1201]);
    expect(app(PaymentsDoctor::class)->inspect()['financial_attempt_amounts'])->toBeFalse();
});

it('uses configured refunds in native service reads relations restoration and rollback', function (): void {
    config(['nvl-payments.stripe.account_id' => 'acct_configured', 'nvl-payments.allowed_currencies' => ['EUR']]);
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $orders->shouldReceive('resolve')->andReturn(new OrderPaymentSnapshot('order-configured', 'v1', 1000, 'EUR', 'Order', null, false));
    app()->instance(PaymentOrderProvider::class, $orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    $actor = new GenericUser(['id' => 'admin']);
    $ownership = Mockery::mock(ExistingPaymentOwnership::class);
    $ownership->shouldReceive('assertOwned')->once();
    app()->instance(ExistingPaymentOwnership::class, $ownership);
    $payment = ['paymentIntentId' => 'pi_configured', 'chargeId' => 'ch_configured', 'accountId' => 'acct_configured', 'livemode' => false, 'amountMinor' => 1000, 'capturedAmountMinor' => 1000, 'refundedAmountMinor' => 0, 'currency' => 'eur', 'status' => 'succeeded', 'captureMethod' => 'automatic', 'amountCapturableMinor' => null, 'paymentMethodType' => null, 'partialAuthorizationStatus' => null];
    $gateway = FakePaymentGateway::fake(app())
        ->willReturn('resolveExisting', $payment)
        ->willReturn('payment', $payment)
        ->willReturn('refunds', [])
        ->willReturn('refund', ['refundId' => 're_configured', 'paymentIntentId' => 'pi_configured', 'chargeId' => 'ch_configured', 'accountId' => 'acct_configured', 'livemode' => false, 'amountMinor' => 200, 'currency' => 'eur', 'status' => 'succeeded', 'reason' => 'requested_by_customer', 'operationKey' => null]);
    $attempt = app(PaymentCheckout::class)->attach('order-configured', 'pi_configured', (string) Str::uuid(), $actor);
    $refund = app(PaymentRefunds::class)->refund($attempt, 200, 'requested_by_customer', null, (string) Str::uuid(), $actor);
    expect($attempt)->toBeInstanceOf(ConfiguredPaymentAttemptFixture::class)
        ->and($refund)->toBeInstanceOf(ConfiguredPaymentRefundFixture::class)
        ->and($refund->paymentAttempt)->toBeInstanceOf(ConfiguredPaymentAttemptFixture::class)
        ->and(app(OwnershipResourceRegistry::class)->get('payments.attempts')->model)->toBe(ConfiguredPaymentAttemptFixture::class)
        ->and(app(OwnershipResourceRegistry::class)->get('payments.refunds')->model)->toBe(ConfiguredPaymentRefundFixture::class)
        ->and($refund->newQueryForRestoration($refund->id)->sole())->toBeInstanceOf(ConfiguredPaymentRefundFixture::class)
        ->and(app(PaymentReadService::class)->forOrder('order-configured', $actor)['refunds'][0]['refundId'])->toBe($refund->id)
        ->and($gateway->calls('refund'))->toHaveCount(1);
    $connection = $attempt->getConnection();
    $connection->beginTransaction();
    $rollback = PaymentRefund::factory()->create();
    expect($rollback)->toBeInstanceOf(ConfiguredPaymentRefundFixture::class);
    $connection->rollBack();
    expect(ConfiguredPaymentRefundFixture::query()->whereKey($rollback->id)->exists())->toBeFalse();
});

it('rejects incompatible Payments extensions before native persistence', function (string $root, string $class, string $message): void {
    $map = Relation::morphMap();
    Relation::morphMap([], false);
    config(['database.connections.financial_other' => config('database.connections.'.config('database.default'))]);
    try {
        $configuration = new Repository(['nvl-payments' => ['models' => [$root => $class]]]);
        $registry = new PaymentsModelRegistry($configuration, new OwnerRegistry($configuration));
        expect(fn () => $root === 'attempt' ? $registry->attemptModel() : $registry->refundModel())
            ->toThrow(OwnershipConfigurationInvalid::class, $message);
    } finally {
        Relation::morphMap($map, false);
    }
})->with([
    'unrelated class' => ['attempt', stdClass::class, 'concrete subclasses'],
    'required cast' => ['attempt', IncompatibleAttemptCasts::class, 'required package casts'],
    'query hook' => ['refund', IncompatibleRefundHook::class, 'canonical query and persistence hooks'],
    'split child source' => ['refund', SplitRefundSource::class, 'package table, actual connection'],
    'native primary key' => ['attempt', IncompatibleAttemptKey::class, 'native UUID identity'],
]);
