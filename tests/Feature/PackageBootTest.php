<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Exceptions\ProviderRefused;
use Nvl\Payments\Providers\PaymentsServiceProvider;
use Nvl\Payments\Services\PaymentsAvailability;
use Nvl\Payments\Services\StripePaymentGateway;
use Nvl\Payments\Tests\PaymentsTestCase;
use Nvl\Support\Providers\SupportServiceProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

uses(PaymentsTestCase::class);

it('discovers the package without activating routes or vendor migrations', function (): void {
    $loaded = app()->getLoadedProviders();
    expect(config('nvl-payments.enabled'))->toBeFalse()
        ->and(config('nvl-payments.allow_live'))->toBeFalse()
        ->and(config('nvl-payments.migrations.enabled'))->toBeFalse()
        ->and(config('nvl-payments.migrations.allow_destructive_rollback'))->toBeFalse()
        ->and(config('nvl-payments.connection'))->toBeNull()
        ->and(Route::getRoutes()->getByName('nvl.payments.webhook'))->toBeNull()
        ->and(Schema::hasTable(PaymentsTables::Attempts))->toBeFalse()
        ->and($loaded)->toHaveKeys([SupportServiceProvider::class, PaymentsServiceProvider::class])
        ->not->toHaveKeys(['Nvl\\Billing\\Providers\\BillingServiceProvider', 'Nvl\\Tenancy\\Providers\\TenancyServiceProvider']);
});

it('exposes the isolated checkout and Stripe configuration defaults', function (): void {
    expect(config('nvl-payments.stripe'))->toMatchArray([
        'secret' => null,
        'webhook_secret' => null,
        'account_id' => null,
        'livemode' => false,
    ])->and(config('nvl-payments.allowed_currencies'))->toBe(['EUR'])
        ->and(config('nvl-payments.minimum_amounts'))->toBe(['EUR' => 50])
        ->and(config('nvl-payments.checkout'))->toBe([
            'return_hosts' => [],
            'expires_in_minutes' => 120,
            'capture_method' => 'automatic',
        ])->and(config('nvl-payments.reconciliation.batch_size'))->toBe(100);
});

it('publishes config, migrations, and agent skills from the package provider', function (): void {
    foreach (['nvl-payments-config', 'nvl-payments-migrations', 'nvl-payments-skills'] as $tag) {
        $paths = ServiceProvider::pathsToPublish(PaymentsServiceProvider::class, $tag);
        expect($paths)->toHaveCount(1);
        foreach ($paths as $source => $destination) {
            expect(file_exists($source))->toBeTrue()
                ->and($destination)->toBeString()->not->toBeEmpty();
        }
    }
});

it('loads opted-in vendor migrations while payment routes remain disabled', function (): void {
    $migrations = dirname(__DIR__, 2).'/database/migrations/payments';
    config()->set('nvl-payments.enabled', false);
    config()->set('nvl-payments.migrations.enabled', true);

    (new PaymentsServiceProvider(app()))->boot();

    try {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        expect(array_map(realpath(...), app('migrator')->paths()))->toContain(realpath($migrations))
            ->and(Schema::hasTable(PaymentsTables::Attempts))->toBeTrue()
            ->and(Route::getRoutes()->getByName('nvl.payments.webhook'))->toBeNull();
    } finally {
        config()->set('nvl-payments.migrations.allow_destructive_rollback', true);
        $this->artisan('migrate:rollback', ['--force' => true, '--path' => [$migrations], '--realpath' => true])->assertExitCode(0);
    }
});

it('resolves the default gateway from Payments credentials account and mode', function (): void {
    config()->set('nvl-payments.enabled', true);
    config()->set('nvl-payments.allow_live', true);
    config()->set('nvl-payments.stripe', ['secret' => 'sk_live_payments_only', 'account_id' => 'acct_configured', 'livemode' => true]);
    $transport = Mockery::mock(ClientInterface::class);
    $transport->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers): bool {
        return $method === 'get' && str_ends_with($url, '/v1/account') && in_array('Authorization: Bearer sk_live_payments_only', $headers, true);
    })->andReturn([json_encode(['object' => 'account', 'id' => 'acct_configured']), 200, []]);
    $transport->shouldReceive('request')->once()->withArgs(fn ($method, $url): bool => $method === 'get' && str_ends_with($url, '/v1/payment_intents/pi_configured'))
        ->andReturn([json_encode(['object' => 'payment_intent', 'id' => 'pi_configured', 'livemode' => true, 'amount' => 100, 'amount_received' => 100, 'currency' => 'eur', 'status' => 'succeeded', 'capture_method' => 'automatic', 'latest_charge' => null]), 200, []]);
    ApiRequestor::setHttpClient($transport);
    try {
        $gateway = app(PaymentGateway::class);
        expect($gateway)->toBeInstanceOf(StripePaymentGateway::class);
        $state = $gateway->payment('pi_configured');
        expect($state['accountId'])->toBe('acct_configured')->and($state['livemode'])->toBeTrue();
    } finally {
        ApiRequestor::setHttpClient(new CurlClient);
    }
});

it('reports local provider readiness and blocks unsafe gateway credentials before transport', function (?string $secret, bool $enabled, bool $liveMode, bool $allowLive, bool $expectedReady): void {
    config([
        'nvl-payments.enabled' => $enabled,
        'nvl-payments.stripe.secret' => $secret,
        'nvl-payments.stripe.account_id' => 'acct_configured',
        'nvl-payments.stripe.livemode' => $liveMode,
        'nvl-payments.allow_live' => $allowLive,
    ]);
    expect(app(PaymentsAvailability::class)->acceptsPayments())->toBe($expectedReady);
    if (! $expectedReady) {
        $transport = Mockery::mock(ClientInterface::class);
        $transport->shouldNotReceive('request');
        ApiRequestor::setHttpClient($transport);
        try {
            expect(fn () => app(PaymentGateway::class)->payment('pi_unsafe'))->toThrow(ProviderRefused::class);
        } finally {
            ApiRequestor::setHttpClient(new CurlClient);
        }
    }
})->with([
    'disabled test key' => ['sk_test_example', false, false, false, false],
    'missing key' => [null, true, false, false, false],
    'malformed key' => ['sk_test_invalid key', true, false, false, false],
    'live key without opt-in' => ['sk_live_example', true, true, false, false],
    'restricted live key without opt-in' => ['rk_live_example', true, true, false, false],
    'live key marked as test' => ['sk_live_example', true, false, true, false],
    'test key marked as live' => ['sk_test_example', true, true, true, false],
    'valid test key' => ['sk_test_example', true, false, false, true],
    'opted-in live key' => ['rk_live_example', true, true, true, true],
]);

it('prevents gateway reads inside a transaction on the configured Payments connection', function (): void {
    config([
        'nvl-payments.enabled' => true,
        'nvl-payments.stripe.secret' => 'sk_test_payments_only',
        'nvl-payments.stripe.account_id' => 'acct_configured',
        'nvl-payments.connection' => 'payments_guard',
        'database.connections.payments_guard' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);
    $transport = Mockery::mock(ClientInterface::class);
    $transport->shouldNotReceive('request');
    ApiRequestor::setHttpClient($transport);

    $connection = DB::connection('payments_guard');
    $connection->beginTransaction();
    try {
        expect(DB::connection()->transactionLevel())->toBe(0);
        expect(fn () => app(PaymentGateway::class)->payment('pi_configured'))
            ->toThrow(DomainException::class, 'outside a database transaction on the payments connection');
    } finally {
        $connection->rollBack();
        ApiRequestor::setHttpClient(new CurlClient);
    }
});

it('preserves a host gateway binding when registering Payments', function (): void {
    $gateway = Mockery::mock(PaymentGateway::class);
    app()->instance(PaymentGateway::class, $gateway);
    (new PaymentsServiceProvider(app()))->register();
    expect(app(PaymentGateway::class))->toBe($gateway);
});

it('preserves host order, authorization, and ownership bindings during discovery', function (): void {
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $ownership = Mockery::mock(ExistingPaymentOwnership::class);
    app()->instance(PaymentOrderProvider::class, $orders);
    app()->instance(PaymentManagementAccess::class, $access);
    app()->instance(ExistingPaymentOwnership::class, $ownership);

    (new PaymentsServiceProvider(app()))->register();

    expect(app(PaymentOrderProvider::class))->toBe($orders)
        ->and(app(PaymentManagementAccess::class))->toBe($access)
        ->and(app(ExistingPaymentOwnership::class))->toBe($ownership);
});
