<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Providers\PaymentsServiceProvider;
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
        ->and(config('nvl-payments.migrations.enabled'))->toBeFalse()
        ->and(config('nvl-payments.connection'))->toBeNull()
        ->and(Route::getRoutes()->getByName('nvl.payments.webhook'))->toBeNull()
        ->and(Schema::hasTable(PaymentsTables::Attempts))->toBeFalse()
        ->and($loaded)->toHaveKeys([SupportServiceProvider::class, DataServiceProvider::class, PaymentsServiceProvider::class])
        ->not->toHaveKeys(['Nvl\\Billing\\Providers\\BillingServiceProvider', 'Nvl\\Tenancy\\Providers\\TenancyServiceProvider']);
});

it('exposes the isolated checkout and Stripe configuration defaults', function (): void {
    expect(config('nvl-payments.stripe'))->toMatchArray([
        'secret' => null,
        'webhook_secret' => null,
        'account_id' => null,
        'livemode' => false,
    ])->and(config('nvl-payments.allowed_currencies'))->toBe([])
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
    config()->set('nvl-payments.enabled', false);
    config()->set('nvl-payments.migrations.enabled', true);

    (new PaymentsServiceProvider(app()))->boot();

    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

    expect(array_map(realpath(...), app('migrator')->paths()))->toContain(dirname(__DIR__, 2).'/database/migrations/payments')
        ->and(Schema::hasTable(PaymentsTables::Attempts))->toBeTrue()
        ->and(Route::getRoutes()->getByName('nvl.payments.webhook'))->toBeNull();
});

it('resolves the default gateway from Payments credentials account and mode', function (): void {
    config()->set('nvl-payments.stripe', ['secret' => 'sk_test_payments_only', 'account_id' => 'acct_configured', 'livemode' => true]);
    $transport = Mockery::mock(ClientInterface::class);
    $transport->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers): bool {
        return $method === 'get' && str_ends_with($url, '/v1/account') && in_array('Authorization: Bearer sk_test_payments_only', $headers, true);
    })->andReturn([json_encode(['object' => 'account', 'id' => 'acct_configured']), 200, []]);
    $transport->shouldReceive('request')->once()->withArgs(fn ($method, $url): bool => $method === 'get' && str_ends_with($url, '/v1/payment_intents/pi_configured'))
        ->andReturn([json_encode(['object' => 'payment_intent', 'id' => 'pi_configured', 'livemode' => true, 'amount' => 100, 'amount_received' => 100, 'currency' => 'eur', 'status' => 'succeeded', 'capture_method' => 'automatic', 'latest_charge' => null]), 200, []]);
    ApiRequestor::setHttpClient($transport);
    try {
        $gateway = app(PaymentGateway::class);
        expect($gateway)->toBeInstanceOf(StripePaymentGateway::class);
        $state = $gateway->payment('pi_configured');
        expect($state->accountId)->toBe('acct_configured')->and($state->livemode)->toBeTrue();
    } finally {
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
