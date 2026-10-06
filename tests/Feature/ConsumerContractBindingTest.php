<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Nvl\Payments\Actions\AttachExistingPaymentAction;
use Nvl\Payments\Actions\CancelAuthorizationAction;
use Nvl\Payments\Actions\CapturePaymentAction;
use Nvl\Payments\Actions\RecoverCheckoutAction;
use Nvl\Payments\Actions\RefundPaymentAction;
use Nvl\Payments\Actions\ResolvePaymentExceptionAction;
use Nvl\Payments\Actions\StartCheckoutAction;
use Nvl\Payments\Contracts\AttachExistingPaymentContract;
use Nvl\Payments\Contracts\CancelAuthorizationContract;
use Nvl\Payments\Contracts\CapturePaymentContract;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Contracts\PaymentReadContract;
use Nvl\Payments\Contracts\RecoverCheckoutContract;
use Nvl\Payments\Contracts\RefundPaymentContract;
use Nvl\Payments\Contracts\ResolvePaymentExceptionContract;
use Nvl\Payments\Contracts\StartCheckoutContract;
use Nvl\Payments\Providers\PaymentsServiceProvider;
use Nvl\Payments\Services\AuthorizationOperations;
use Nvl\Payments\Services\DenyExistingPaymentOwnership;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Services\DenyPaymentOrderProvider;
use Nvl\Payments\Services\PaymentOperationJournal;
use Nvl\Payments\Services\PaymentProjection;
use Nvl\Payments\Services\PaymentReadService;
use Nvl\Payments\Services\PaymentReconciler;
use Nvl\Payments\Services\PaymentStateSyncer;
use Nvl\Payments\Services\RefundBalance;
use Nvl\Payments\Services\StripePaymentGateway;
use Nvl\Payments\Tests\Fixtures\PaymentsConsumerWorkflow;
use Nvl\Payments\Tests\PaymentsTestCase;
use Nvl\Payments\ValueObjects\HostedCheckout;
use Nvl\Payments\ValueObjects\OrderPaymentTimeline;
use Nvl\Payments\ValueObjects\PaymentSnapshot;
use Nvl\Payments\ValueObjects\RefundSnapshot;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

uses(PaymentsTestCase::class);

dataset('payments consumer contracts', [
    'attachment' => [AttachExistingPaymentContract::class, AttachExistingPaymentAction::class, 'execute', [
        ['orderReference', 'string'], ['actor', Authenticatable::class], ['stripeReference', 'string'], ['operationId', 'string'],
    ], PaymentSnapshot::class],
    'cancellation' => [CancelAuthorizationContract::class, CancelAuthorizationAction::class, 'execute', [
        ['attemptId', 'string'], ['actor', Authenticatable::class], ['operationId', 'string'],
    ], PaymentSnapshot::class],
    'capture' => [CapturePaymentContract::class, CapturePaymentAction::class, 'execute', [
        ['attemptId', 'string'], ['actor', Authenticatable::class], ['amountMinor', 'int'], ['operationId', 'string'],
    ], PaymentSnapshot::class],
    'recovery' => [RecoverCheckoutContract::class, RecoverCheckoutAction::class, 'execute', [
        ['attemptId', 'string'], ['stripeSessionId', 'string'], ['actor', Authenticatable::class],
    ], PaymentSnapshot::class],
    'refund' => [RefundPaymentContract::class, RefundPaymentAction::class, 'execute', [
        ['attemptId', 'string'], ['actor', Authenticatable::class], ['amountMinor', 'int'], ['reason', 'string'], ['note', '?string'], ['operationId', 'string'],
    ], RefundSnapshot::class],
    'exception' => [ResolvePaymentExceptionContract::class, ResolvePaymentExceptionAction::class, 'execute', [
        ['attemptId', 'string'], ['actor', Authenticatable::class], ['operationId', 'string'],
    ], PaymentSnapshot::class],
    'checkout' => [StartCheckoutContract::class, StartCheckoutAction::class, 'execute', [
        ['orderReference', 'string'], ['actor', Authenticatable::class], ['successUrl', 'string'], ['cancelUrl', 'string'],
    ], HostedCheckout::class],
    'timeline' => [PaymentReadContract::class, PaymentReadService::class, 'forOrder', [
        ['orderReference', 'string'], ['actor', Authenticatable::class],
    ], OrderPaymentTimeline::class],
]);

beforeEach(function (): void {
    $this->paymentsContractTransport = new class implements ClientInterface
    {
        public int $requests = 0;

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->requests++;

            throw new LogicException('Contract composition must not send Stripe requests.');
        }
    };
    ApiRequestor::setHttpClient($this->paymentsContractTransport);
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(new CurlClient);
});

it('preserves complete native signatures and method documentation', function (string $contract, string $implementation, string $method, array $parameters, string $result): void {
    expect(interface_exists($contract))->toBeTrue();
    $interface = new ReflectionClass($contract);
    $concrete = new ReflectionClass($implementation);
    $nativeMethods = array_values(array_filter($concrete->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $member): bool => ! $member->isConstructor() && ! $member->isStatic()));
    expect($concrete->implementsInterface($contract))->toBeTrue()
        ->and($concrete->isFinal())->toBeTrue()
        ->and($concrete->isReadOnly())->toBeFalse()
        ->and($concrete->getAttributes())->toBe([])
        ->and($interface->getAttributes())->toBe([])
        ->and($interface->getDocComment())->toContain('@api')
        ->and(array_map(static fn (ReflectionMethod $member): string => $member->getName(), $interface->getMethods()))->toBe([$method])
        ->and(array_map(static fn (ReflectionMethod $member): string => $member->getName(), $nativeMethods))->toBe([$method]);
    $native = $concrete->getMethod($method);
    $declared = $interface->getMethod($method);
    foreach ([$native, $declared] as $member) {
        expect($member->isPublic())->toBeTrue()
            ->and($member->isStatic())->toBeFalse()
            ->and((string) $member->getReturnType())->toBe($result)
            ->and($member->getReturnType()->allowsNull())->toBeFalse()
            ->and($member->returnsReference())->toBeFalse()
            ->and($member->getAttributes())->toBe([])
            ->and(count($member->getParameters()))->toBe(count($parameters));
        foreach ($member->getParameters() as $position => $parameter) {
            expect([$parameter->getName(), (string) $parameter->getType()])->toBe($parameters[$position])
                ->and($parameter->allowsNull())->toBe($parameters[$position][1] === '?string')
                ->and($parameter->isOptional())->toBeFalse()
                ->and($parameter->isPassedByReference())->toBeFalse()
                ->and($parameter->isVariadic())->toBeFalse()
                ->and($parameter->isDefaultValueAvailable())->toBeFalse()
                ->and($parameter->getAttributes())->toBe([]);
        }
    }
    expect($declared->getDocComment())->toBe($native->getDocComment());
})->with('payments consumer contracts');

it('retains the native constructors and private readonly promoted dependencies', function (): void {
    $expected = [
        AttachExistingPaymentAction::class => [['orders', PaymentOrderProvider::class], ['access', PaymentManagementAccess::class], ['ownership', ExistingPaymentOwnership::class], ['gateway', PaymentGateway::class], ['journal', PaymentOperationJournal::class], ['syncer', PaymentStateSyncer::class]],
        CancelAuthorizationAction::class => [['operations', AuthorizationOperations::class]],
        CapturePaymentAction::class => [['operations', AuthorizationOperations::class]],
        RecoverCheckoutAction::class => [['orders', PaymentOrderProvider::class], ['access', PaymentManagementAccess::class], ['reconciler', PaymentReconciler::class]],
        RefundPaymentAction::class => [['orders', PaymentOrderProvider::class], ['access', PaymentManagementAccess::class], ['gateway', PaymentGateway::class], ['journal', PaymentOperationJournal::class], ['syncer', PaymentStateSyncer::class], ['balance', RefundBalance::class]],
        ResolvePaymentExceptionAction::class => [['orders', PaymentOrderProvider::class], ['access', PaymentManagementAccess::class], ['gateway', PaymentGateway::class], ['journal', PaymentOperationJournal::class], ['syncer', PaymentStateSyncer::class], ['projection', PaymentProjection::class]],
        StartCheckoutAction::class => [['orders', PaymentOrderProvider::class], ['access', PaymentManagementAccess::class], ['gateway', PaymentGateway::class], ['journal', PaymentOperationJournal::class], ['syncer', PaymentStateSyncer::class]],
        PaymentReadService::class => [['orders', PaymentOrderProvider::class], ['access', PaymentManagementAccess::class], ['projection', PaymentProjection::class]],
    ];
    foreach ($expected as $implementation => $parameters) {
        $concrete = new ReflectionClass($implementation);
        $constructor = $concrete->getConstructor();
        expect($constructor->isPublic())->toBeTrue()->and($constructor->getAttributes())->toBe([])
            ->and(count($constructor->getParameters()))->toBe(count($parameters));
        foreach ($constructor->getParameters() as $position => $parameter) {
            $property = $concrete->getProperty($parameter->getName());
            expect([$parameter->getName(), (string) $parameter->getType()])->toBe($parameters[$position])
                ->and($parameter->isPromoted())->toBeTrue()
                ->and($parameter->isOptional())->toBeFalse()
                ->and($parameter->allowsNull())->toBeFalse()
                ->and($parameter->isDefaultValueAvailable())->toBeFalse()
                ->and($parameter->isPassedByReference())->toBeFalse()
                ->and($parameter->isVariadic())->toBeFalse()
                ->and($parameter->getAttributes())->toBe([])
                ->and($property->isPrivate())->toBeTrue()
                ->and($property->isReadOnly())->toBeTrue()
                ->and($property->getAttributes())->toBe([]);
        }
    }
});

it('resolves transient native defaults and concrete classes in independent applications', function (string $contract, string $implementation): void {
    expect(interface_exists($contract))->toBeTrue();
    $first = paymentsContractApplication($this->app);
    try {
        $first->register(PaymentsServiceProvider::class);
        $resolved = $first->make($contract);
        expect($resolved)->toBeInstanceOf($implementation)->not->toBe($first->make($contract))
            ->not->toBe($first->make($implementation))
            ->and($first->make($implementation))->toBeInstanceOf($implementation)
            ->and($first->make('config')->get('nvl-payments.enabled'))->toBeFalse()
            ->and($first->make('config')->get('nvl-payments.migrations.enabled'))->toBeFalse();
        $replacement = Mockery::mock($contract);
        $first->instance($contract, $replacement);
        $second = paymentsContractApplication($this->app);
        try {
            $second->register(PaymentsServiceProvider::class);
            $next = $second->make($contract);
            expect($next)->toBeInstanceOf($implementation)->not->toBe($resolved)->not->toBe($replacement)
                ->not->toBe($second->make($contract))
                ->and($first->make($contract))->toBe($replacement)
                ->and($this->paymentsContractTransport->requests)->toBe(0);
        } finally {
            $second->flush();
        }
    } finally {
        restorePaymentsContractApplication($this->app, $first);
    }
})->with('payments consumer contracts');

it('preserves conditional native gateway and host extension defaults', function (): void {
    $consumer = paymentsContractApplication($this->app);
    try {
        $consumer->register(PaymentsServiceProvider::class);
        foreach ([PaymentGateway::class => StripePaymentGateway::class, PaymentOrderProvider::class => DenyPaymentOrderProvider::class, PaymentManagementAccess::class => DenyPaymentManagementAccess::class, ExistingPaymentOwnership::class => DenyExistingPaymentOwnership::class] as $contract => $implementation) {
            expect($consumer->make($contract))->toBeInstanceOf($implementation);
            $substitute = Mockery::mock($contract);
            $consumer->instance($contract, $substitute);
            $consumer->register(PaymentsServiceProvider::class, force: true);
            expect($consumer->make($contract))->toBe($substitute);
        }
        expect($this->paymentsContractTransport->requests)->toBe(0);
    } finally {
        restorePaymentsContractApplication($this->app, $consumer);
    }
});

it('preserves host instances installed before provider discovery', function (string $contract): void {
    expect(interface_exists($contract))->toBeTrue();
    $consumer = paymentsContractApplication($this->app);
    $substitute = Mockery::mock($contract);
    $consumer->instance($contract, $substitute);
    try {
        $consumer->register(PaymentsServiceProvider::class);
        expect($consumer->make(PaymentsConsumerWorkflow::class)->dependency($contract))->toBe($substitute);
        $consumer->register(PaymentsServiceProvider::class, force: true);
        expect($consumer->make(PaymentsConsumerWorkflow::class)->dependency($contract))->toBe($substitute);
    } finally {
        restorePaymentsContractApplication($this->app, $consumer);
    }
})->with('payments consumer contracts');

it('preserves lazy host closures installed before provider discovery', function (string $contract): void {
    expect(interface_exists($contract))->toBeTrue();
    $consumer = paymentsContractApplication($this->app);
    $substitute = Mockery::mock($contract);
    $calls = 0;
    $consumer->bind($contract, static function () use ($substitute, &$calls): object {
        $calls++;

        return $substitute;
    });
    try {
        $consumer->register(PaymentsServiceProvider::class);
        expect($calls)->toBe(0);
        expect($consumer->make(PaymentsConsumerWorkflow::class)->dependency($contract))->toBe($substitute)->and($calls)->toBe(1);
        $consumer->register(PaymentsServiceProvider::class, force: true);
        expect($calls)->toBe(1);
        expect($consumer->make(PaymentsConsumerWorkflow::class)->dependency($contract))->toBe($substitute)->and($calls)->toBe(2);
    } finally {
        restorePaymentsContractApplication($this->app, $consumer);
    }
})->with('payments consumer contracts');

it('composes declared results through real host injection without package effects', function (string $binding): void {
    foreach (paymentsContractImplementations() as $contract => $implementation) {
        expect(interface_exists($contract))->toBeTrue();
    }
    $actor = new GenericUser(['id' => 'payments-host-actor']);
    $checkout = new HostedCheckout('cs_host', 'https://checkout.example.test/host', CarbonImmutable::parse('2030-01-01T00:00:00Z'));
    $payment = new PaymentSnapshot('attempt-host', 'order-host', 'revision-host', 1200, 1200, 200, 'EUR', 'partially_refunded', 'attached', null, 'pi_host', 'ch_host', 'acct_host', false, 'automatic', null, null, 300, 700);
    $refund = new RefundSnapshot('refund-host', 'attempt-host', 'operation-refund', 200, 'EUR', 'requested_by_customer', 'succeeded', 're_host', null);
    $timeline = new OrderPaymentTimeline('order-host', [$payment], [$refund]);
    $substitutes = [];
    $arguments = [
        AttachExistingPaymentContract::class => ['order-host', $actor, 'pi_host', 'operation-attach'],
        CancelAuthorizationContract::class => ['attempt-cancel', $actor, 'operation-cancel'],
        CapturePaymentContract::class => ['attempt-capture', $actor, 1200, 'operation-capture'],
        RecoverCheckoutContract::class => ['attempt-recover', 'cs_recover', $actor],
        RefundPaymentContract::class => ['attempt-host', $actor, 200, 'requested_by_customer', null, 'operation-refund'],
        ResolvePaymentExceptionContract::class => ['attempt-exception', $actor, 'operation-exception'],
        StartCheckoutContract::class => ['order-host', $actor, 'https://app.example.test/success', 'https://app.example.test/cancel'],
        PaymentReadContract::class => ['order-host', $actor],
    ];
    foreach ($arguments as $contract => $inputs) {
        $substitute = Mockery::mock($contract);
        $result = match ($contract) {
            RefundPaymentContract::class => $refund,
            StartCheckoutContract::class => $checkout,
            PaymentReadContract::class => $timeline,
            default => $payment,
        };
        $substitute->shouldReceive($contract === PaymentReadContract::class ? 'forOrder' : 'execute')->once()->with(...$inputs)->andReturn($result);
        $substitutes[$contract] = $substitute;
    }
    $connection = DB::connection();
    $consumer = paymentsContractApplication($this->app);
    $closureCalls = array_fill_keys(array_keys($substitutes), 0);
    try {
        if ($binding !== 'late') {
            foreach ($substitutes as $contract => $substitute) {
                if ($binding === 'closure') {
                    $consumer->bind($contract, static function () use ($substitute, $contract, &$closureCalls): object {
                        $closureCalls[$contract]++;

                        return $substitute;
                    });
                } else {
                    $consumer->instance($contract, $substitute);
                }
            }
        }
        $consumer->register(PaymentsServiceProvider::class);
        expect(array_sum($closureCalls))->toBe(0);
        if ($binding === 'late') {
            $original = $consumer->make(PaymentsConsumerWorkflow::class);
            foreach (paymentsContractImplementations() as $contract => $implementation) {
                expect($original->dependency($contract))->toBeInstanceOf($implementation);
                $consumer->instance($contract, $substitutes[$contract]);
                expect($original->dependency($contract))->toBeInstanceOf($implementation);
            }
        }
        $resolutions = [];
        foreach ([...array_values(paymentsContractImplementations()), PaymentGateway::class, PaymentOrderProvider::class, PaymentManagementAccess::class, ExistingPaymentOwnership::class, StripePaymentGateway::class, StripeClient::class, AuthorizationOperations::class, PaymentReconciler::class, PaymentOperationJournal::class, PaymentStateSyncer::class, RefundBalance::class, PaymentProjection::class, 'db', 'db.connection', 'events', 'filesystem', Filesystem::class] as $dependency) {
            $resolutions[$dependency] = 0;
            $consumer->bind($dependency, static function () use (&$resolutions, $dependency): never {
                $resolutions[$dependency]++;

                throw new LogicException('Real Payments work must not resolve: '.$dependency);
            });
        }
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $host = $consumer->make(PaymentsConsumerWorkflow::class);
        $outcome = $host->prepare($actor);
        foreach (['attached', 'canceled', 'captured', 'recovered', 'accepted'] as $key) {
            expect($outcome[$key])->toBe($payment);
        }
        expect($outcome['checkout'])->toBe($checkout)
            ->and($outcome['url'])->toBe('https://checkout.example.test/host')
            ->and($outcome['checkout']->expiresAt->toIso8601String())->toBe('2030-01-01T00:00:00+00:00')
            ->and($outcome['refund'])->toBe($refund)
            ->and($outcome['refund']->stripeRefundId)->toBe('re_host')
            ->and($outcome['timeline'])->toBe($timeline)
            ->and($outcome['timeline']->payments)->toBe([$payment])
            ->and($outcome['timeline']->refunds)->toBe([$refund])
            ->and($outcome['refundable'])->toBe(700)
            ->and(array_sum($resolutions))->toBe(0)
            ->and($connection->getQueryLog())->toBe([])
            ->and($this->paymentsContractTransport->requests)->toBe(0)
            ->and(array_values($closureCalls))->toBe(array_fill(0, 8, $binding === 'closure' ? 1 : 0));
    } finally {
        $connection->disableQueryLog();
        restorePaymentsContractApplication($this->app, $consumer);
    }
})->with(['instance' => 'instance', 'closure' => 'closure', 'late replacement' => 'late']);

/** Create an actual disabled Payments application with local test credentials. */
function paymentsContractApplication(Application $original): Application
{
    $consumer = new Application($original->basePath());
    $configuration = new Repository($original->make('config')->all());
    $configuration->set([
        'nvl-payments.enabled' => false,
        'nvl-payments.migrations.enabled' => false,
        'nvl-payments.stripe.secret' => 'sk_test_contracts',
        'nvl-payments.stripe.account_id' => 'acct_contracts',
        'nvl-payments.stripe.livemode' => false,
    ]);
    $consumer->instance('config', $configuration);
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($consumer);

    return $consumer;
}

/** Restore global container and facade state after an independent application. */
function restorePaymentsContractApplication(Application $original, Application $consumer): void
{
    Container::setInstance($original);
    Facade::setFacadeApplication($original);
    Facade::clearResolvedInstances();
    $consumer->flush();
}

/**
 * Map the eight supported contracts to their native implementations.
 *
 * @return array<class-string, class-string>
 */
function paymentsContractImplementations(): array
{
    return [
        AttachExistingPaymentContract::class => AttachExistingPaymentAction::class,
        CancelAuthorizationContract::class => CancelAuthorizationAction::class,
        CapturePaymentContract::class => CapturePaymentAction::class,
        RecoverCheckoutContract::class => RecoverCheckoutAction::class,
        RefundPaymentContract::class => RefundPaymentAction::class,
        ResolvePaymentExceptionContract::class => ResolvePaymentExceptionAction::class,
        StartCheckoutContract::class => StartCheckoutAction::class,
        PaymentReadContract::class => PaymentReadService::class,
    ];
}
