<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Payments\Actions\ReconcilePaymentAction;
use Nvl\Payments\Actions\StartCheckoutAction;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\PaymentOperationJournal;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\HostedCheckout;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Nvl\Payments\ValueObjects\StripePaymentState;

require_once __DIR__.'/../PaymentsTestCase.php';

uses(PaymentsSchemaTestCase::class);

/** Build independent host facts for the Checkout workflow. */
function checkoutOrder(string $revision = 'v1', bool $payable = true): OrderPaymentSnapshot
{
    return new OrderPaymentSnapshot('order-1', $revision, 1299, 'JPY', 'Order one', null, $payable);
}

/** Invoke the public checkout use case. */
function startTestCheckout(string $success = 'https://shop.test/success', string $cancel = 'https://shop.test/cancel'): HostedCheckout
{
    return app(StartCheckoutAction::class)->execute('order-1', new GenericUser(['id' => 'admin-1']), $success, $cancel);
}

/** Build authoritative provider session facts. */
function checkoutState(string $status = 'open', string $payment = 'unpaid'): StripeCheckoutState
{
    return new StripeCheckoutState('cs_1', null, 'acct_test', false, 1299, 'jpy', $status, $payment, 'order-1', 'v1', CarbonImmutable::now()->addHour());
}

beforeEach(function (): void {
    config(['payments.allowed_currencies' => ['JPY'], 'payments.checkout.return_hosts' => ['shop.test'], 'payments.stripe.account_id' => 'acct_test']);
    $this->orders = Mockery::mock(PaymentOrderProvider::class);
    $this->orders->shouldReceive('resolve')->byDefault()->andReturn(checkoutOrder());
    app()->instance(PaymentOrderProvider::class, $this->orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $operation, $order) => $actor->getAuthIdentifier() === 'admin-1' && $operation === 'start_checkout' && $order->reference === 'order-1');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway = Mockery::mock(PaymentGateway::class);
    app()->instance(PaymentGateway::class, $this->gateway);
});

it('reserves before Stripe outside transactions and reuses the original hosted URL and JPY minor units', function (): void {
    $this->gateway->shouldReceive('createCheckout')->once()->andReturnUsing(function ($order, $capture, $success, $cancel, $expiry, $key): HostedCheckout {
        expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0)
            ->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull()
            ->and(PaymentOperation::sole()->status)->toBe('reserved')
            ->and(PaymentOperation::sole()->idempotency_key)->toBe($key)
            ->and($order->amountMinor)->toBe(1299);

        return new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', $expiry);
    });
    $this->gateway->shouldReceive('checkout')->once()->with('cs_1')->andReturn(checkoutState());
    $first = startTestCheckout();
    $again = startTestCheckout();
    expect($again->url)->toBe($first->url)->and($again->sessionId)->toBe('cs_1')
        ->and(PaymentAttempt::count())->toBe(1)->and(PaymentOperation::sole()->status)->toBe('completed');
});

it('keeps failed or ambiguous creates reserved and blocks subsequent creates', function (): void {
    $this->gateway->shouldReceive('createCheckout')->once()->andThrow(new RuntimeException('API timeout'));
    expect(fn () => startTestCheckout())->toThrow(RuntimeException::class);
    expect(PaymentOperation::sole()->status)->toBe('unknown')->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull();
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
});

it('blocks competing requests while the first remote call is running', function (): void {
    $this->gateway->shouldReceive('createCheckout')->once()->andReturnUsing(function ($order, $capture, $success, $cancel, $expiry): HostedCheckout {
        expect(fn () => startTestCheckout())->toThrow(DomainException::class);

        return new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', $expiry);
    });
    startTestCheckout();
    expect(PaymentAttempt::count())->toBe(1);
});

it('blocks processing and paid attempts including imported attempts without reservations', function (string $state): void {
    PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1299, 'currency' => 'JPY', 'origin' => 'attached', 'state' => $state]);
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect(PaymentAttempt::count())->toBe(1);
})->with(['processing', 'captured', 'authorized', 'payment_exception']);

it('rejects nonpayable orders and disallowed currencies before reserving', function (bool $payable, array $currencies): void {
    $this->orders->shouldReceive('resolve')->andReturn(checkoutOrder(payable: $payable));
    config(['payments.allowed_currencies' => $currencies]);
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect(PaymentAttempt::count())->toBe(0);
})->with([[false, ['JPY']], [true, ['USD']]]);

it('rejects either return URL outside the configured HTTPS hosts', function (string $url): void {
    expect(fn () => startTestCheckout(success: $url))->toThrow(InvalidArgumentException::class);
    expect(fn () => startTestCheckout(cancel: $url))->toThrow(InvalidArgumentException::class);
    expect(PaymentAttempt::count())->toBe(0);
})->with(['http://shop.test/success', 'https://evil.test', 'https://shop.test.evil.test', 'https://user:secret@shop.test', '//shop.test/success', 'https://shop.test:444/success']);

it('replaces an expired session only after authoritative confirmation', function (): void {
    $this->gateway->shouldReceive('createCheckout')->twice()->andReturn(
        new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', CarbonImmutable::now()->subMinute()),
        new HostedCheckout('cs_2', 'https://checkout.stripe.com/two', CarbonImmutable::now()->addHour()),
    );
    $this->gateway->shouldReceive('checkout')->once()->andReturn(checkoutState('expired'));
    startTestCheckout();
    expect(startTestCheckout()->sessionId)->toBe('cs_2')
        ->and(PaymentAttempt::whereNotNull('reservation_key')->count())->toBe(1)
        ->and(PaymentAttempt::where('stripe_checkout_session_id', 'cs_1')->sole()->state)->toBe('expired');
});

it('expires and verifies an old revision before issuing its replacement', function (): void {
    $this->gateway->shouldReceive('createCheckout')->twice()->andReturn(
        new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', CarbonImmutable::now()->addHour()),
        new HostedCheckout('cs_2', 'https://checkout.stripe.com/two', CarbonImmutable::now()->addHour()),
    );
    startTestCheckout();
    $this->orders->shouldReceive('resolve')->andReturn(checkoutOrder('v2'));
    $this->gateway->shouldReceive('checkout')->twice()->andReturn(checkoutState(), checkoutState('expired'));
    $this->gateway->shouldReceive('expireCheckout')->once()->andReturnUsing(function (): void {
        expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0)
            ->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull()
            ->and(PaymentOperation::where('type', 'expire_checkout')->sole()->status)->toBe('reserved');
    });
    expect(startTestCheckout()->sessionId)->toBe('cs_2')->and(PaymentAttempt::count())->toBe(2);
});

it('records an exception when the old revision is already paid', function (): void {
    $this->gateway->shouldReceive('createCheckout')->once()->andReturn(new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', CarbonImmutable::now()->addHour()));
    startTestCheckout();
    $this->orders->shouldReceive('resolve')->andReturn(checkoutOrder('v2'));
    $this->gateway->shouldReceive('checkout')->once()->andReturn(checkoutState('complete', 'paid'));
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect(PaymentAttempt::sole()->state)->toBe('payment_exception')->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull();
});

it('keeps uncertain expiry blocked pending reconciliation', function (): void {
    $this->gateway->shouldReceive('createCheckout')->once()->andReturn(new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', CarbonImmutable::now()->addHour()));
    startTestCheckout();
    $this->orders->shouldReceive('resolve')->andReturn(checkoutOrder('v2'));
    $this->gateway->shouldReceive('checkout')->once()->andReturn(checkoutState());
    $this->gateway->shouldReceive('expireCheckout')->once()->andThrow(new RuntimeException('timeout'));
    expect(fn () => startTestCheckout())->toThrow(RuntimeException::class);
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect(PaymentOperation::where('type', 'expire_checkout')->sole()->status)->toBe('unknown')
        ->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull();
});

it('reserves identical operation UUIDs once and rejects every changed input', function (): void {
    $journal = app(PaymentOperationJournal::class);
    $id = (string) Str::uuid();
    $original = $journal->reserve('refund', 'order-1', $id, 'admin-1', 'fingerprint', 100);
    expect($journal->reserve('refund', 'order-1', $id, 'admin-1', 'fingerprint', 100)->id)->toBe($original->id);
    foreach ([['capture', 'order-1', 'admin-1', 'fingerprint', 100], ['refund', 'order-2', 'admin-1', 'fingerprint', 100], ['refund', 'order-1', 'admin-2', 'fingerprint', 100], ['refund', 'order-1', 'admin-1', 'changed', 100], ['refund', 'order-1', 'admin-1', 'fingerprint', 101]] as [$kind, $subject, $actor, $fingerprint, $amount]) {
        expect(fn () => $journal->reserve($kind, $subject, $id, $actor, $fingerprint, $amount))->toThrow(DomainException::class);
    }
    expect(PaymentOperation::count())->toBe(1);
});

it('resolves unknown operations idempotently and never regresses a completed result', function (): void {
    $journal = app(PaymentOperationJournal::class);
    $operation = $journal->reserve('capture', 'order-1', (string) Str::uuid(), 'admin-1', 'fingerprint', 100);
    $journal->markUnknown($operation);
    expect($operation->refresh()->status)->toBe('unknown')->and($operation->resolved_at)->toBeNull();
    $journal->complete($operation, 'pi_1', 'req_1');
    $journal->complete($operation, 'pi_1', 'req_1');
    $journal->markUnknown($operation);
    expect($operation->refresh()->status)->toBe('completed')->and($operation->resolved_at)->not->toBeNull()
        ->and($operation->stripe_result_reference)->toBe('pi_1')->and($operation->stripe_request_reference)->toBe('req_1');
    expect(fn () => $journal->complete($operation, 'pi_other'))->toThrow(DomainException::class);
});

it('retains the reservation if payment wins the expiry race', function (): void {
    $this->gateway->shouldReceive('createCheckout')->once()->andReturn(new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', CarbonImmutable::now()->addHour()));
    startTestCheckout();
    $this->orders->shouldReceive('resolve')->andReturn(checkoutOrder('v2'));
    $this->gateway->shouldReceive('checkout')->twice()->andReturn(checkoutState(), checkoutState('complete', 'paid'));
    $this->gateway->shouldReceive('expireCheckout')->once();
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect(PaymentAttempt::sole()->state)->toBe('payment_exception')
        ->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull()
        ->and(PaymentOperation::where('type', 'expire_checkout')->sole()->status)->toBe('unknown');
});

it('does not create a Session inside an outer transaction', function (): void {
    (new PaymentAttempt)->getConnection()->transaction(function (): void {
        expect(fn () => startTestCheckout())->toThrow(DomainException::class);
        expect(PaymentAttempt::count())->toBe(0);
    });
});

it('reserves one Session across two competing SQLite file processes', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl for the real process race.');
    }
    $directory = sys_get_temp_dir().'/payments-checkout-'.Str::uuid();
    mkdir($directory);
    touch($directory.'/database.sqlite');
    config(['database.connections.checkout_race' => array_replace(config('database.connections.sqlite'), ['database' => $directory.'/database.sqlite', 'busy_timeout' => 5000]), 'payments.connection' => 'checkout_race']);
    $migration = require __DIR__.'/../../database/migrations/payments/2026_09_28_000001_nvl_payments_create_payments_tables.php';
    $migration->up();
    $waitFor = static function (Closure $condition): void {
        $deadline = microtime(true) + 10;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrency barrier timed out.');
            }
            usleep(1000);
            clearstatcache();
        }
    };
    $children = [];
    try {
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork race worker.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('checkout_race');
                    $gateway = Mockery::mock(PaymentGateway::class);
                    $gateway->shouldReceive('createCheckout')->andReturnUsing(function ($order, $capture, $success, $cancel, $expiry) use ($directory, $waitFor): HostedCheckout {
                        file_put_contents($directory.'/creates', "create\n", FILE_APPEND | LOCK_EX);
                        $waitFor(fn () => file_exists($directory.'/release'));

                        return new HostedCheckout('cs_race', 'https://checkout.stripe.com/race', $expiry);
                    });
                    app()->instance(PaymentGateway::class, $gateway);
                    touch($directory.'/ready-'.$worker);
                    $waitFor(fn () => file_exists($directory.'/start'));
                    try {
                        startTestCheckout();
                        file_put_contents($directory.'/result-'.$worker, 'created');
                    } catch (DomainException $exception) {
                        file_put_contents($directory.'/result-'.$worker, 'blocked');
                    }
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($directory.'/result-'.$worker, get_class($exception).': '.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        $waitFor(fn () => file_exists($directory.'/ready-0') && file_exists($directory.'/ready-1'));
        touch($directory.'/start');
        $waitFor(fn () => file_exists($directory.'/creates') && (file_exists($directory.'/result-0') || file_exists($directory.'/result-1')));
        touch($directory.'/release');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = [file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1')];
        sort($results);
        expect($results)->toBe(['blocked', 'created'])
            ->and(file_get_contents($directory.'/creates'))->toBe("create\n")
            ->and(PaymentAttempt::whereNotNull('reservation_key')->count())->toBe(1)
            ->and(PaymentAttempt::sole()->stripe_checkout_session_id)->toBe('cs_race')
            ->and(PaymentOperation::count())->toBe(1)
            ->and(PaymentOperation::sole()->status)->toBe('completed');
    } finally {
        touch($directory.'/release');
        touch($directory.'/start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::purge('checkout_race');
        config(['payments.connection' => null]);
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('rejects actors without a stable scalar identifier before reserving', function (): void {
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    expect(fn () => app(StartCheckoutAction::class)->execute('order-1', new GenericUser(['id' => []]), 'https://shop.test/success', 'https://shop.test/cancel'))->toThrow(InvalidArgumentException::class);
    expect(PaymentAttempt::count())->toBe(0);
});

it('keeps mismatched remote session facts blocked', function (string $field, mixed $value): void {
    $this->gateway->shouldReceive('createCheckout')->once()->andReturn(new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', CarbonImmutable::now()->addHour()));
    startTestCheckout();
    $values = get_object_vars(checkoutState('expired'));
    $values[$field] = $value;
    $this->gateway->shouldReceive('checkout')->once()->andReturn(new StripeCheckoutState(...$values));
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect(PaymentAttempt::sole()->state)->toBe('unknown')->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull();
})->with([
    ['accountId', 'acct_other'], ['livemode', true], ['amountMinor', 1300],
    ['currency', 'USD'], ['orderReference', 'order-other'], ['orderRevision', 'other'], ['sessionId', 'cs_other'],
]);

it('replaces a reconciled async failed Checkout only after its completed flow cannot collect', function (): void {
    $this->gateway->shouldReceive('createCheckout')->twice()->andReturn(new HostedCheckout('cs_1', 'https://checkout.stripe.com/one', CarbonImmutable::now()->addHour()), new HostedCheckout('cs_2', 'https://checkout.stripe.com/two', CarbonImmutable::now()->addHour()));
    startTestCheckout();
    $attempt = PaymentAttempt::sole();
    $attempt->update(['stripe_payment_intent_id' => 'pi_failed', 'state' => 'processing']);
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState('cs_1', 'pi_failed', 'acct_test', false, 1299, 'jpy', 'complete', 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->addHour()));
    $this->gateway->shouldReceive('payment')->andReturn(new StripePaymentState('pi_failed', null, 'acct_test', false, 1299, 0, 0, 'jpy', 'requires_payment_method', 'automatic', 0));
    app(ReconcilePaymentAction::class)->execute($attempt->id);
    expect($attempt->refresh()->state)->toBe('failed');
    expect(startTestCheckout()->sessionId)->toBe('cs_2')->and($attempt->refresh()->reservation_key)->toBeNull()
        ->and(PaymentAttempt::whereNotNull('reservation_key')->count())->toBe(1);
});

it('keeps a failed Checkout reserved when fresh payment facts are unsafe', function (string $status, int $captured, ?int $capturable): void {
    $attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1299, 'currency' => 'JPY', 'origin' => 'checkout', 'state' => 'failed', 'reservation_key' => hash('sha256', 'order-1'), 'stripe_checkout_session_id' => 'cs_1', 'stripe_payment_intent_id' => 'pi_failed', 'capture_method' => 'automatic']);
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState('cs_1', 'pi_failed', 'acct_test', false, 1299, 'jpy', 'complete', 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->addHour()));
    $this->gateway->shouldReceive('payment')->andReturn(new StripePaymentState('pi_failed', null, 'acct_test', false, 1299, $captured, 0, 'jpy', $status, 'automatic', $capturable));
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect($attempt->refresh()->reservation_key)->not->toBeNull()->and(PaymentAttempt::count())->toBe(1);
})->with([['processing', 0, 0], ['requires_action', 0, 0], ['requires_capture', 0, 1299], ['succeeded', 1299, 0], ['requires_payment_method', 0, null]]);

it('expires a failed open Checkout durably and retains its key through timeout recovery', function (): void {
    $attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1299, 'currency' => 'JPY', 'origin' => 'checkout', 'state' => 'failed', 'reservation_key' => hash('sha256', 'order-1'), 'stripe_checkout_session_id' => 'cs_1', 'stripe_payment_intent_id' => 'pi_failed', 'capture_method' => 'automatic']);
    $session = fn (string $status) => new StripeCheckoutState('cs_1', 'pi_failed', 'acct_test', false, 1299, 'jpy', $status, 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->addHour());
    $this->gateway->shouldReceive('checkout')->andReturn($session('open'), $session('open'), $session('open'), $session('expired'));
    $this->gateway->shouldReceive('payment')->andReturn(new StripePaymentState('pi_failed', null, 'acct_test', false, 1299, 0, 0, 'jpy', 'requires_payment_method', 'automatic', 0));
    $keys = [];
    $this->gateway->shouldReceive('expireCheckout')->twice()->andReturnUsing(function ($id, $key) use (&$keys): void {
        $keys[] = $key;
        expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0)->and(PaymentOperation::sole()->idempotency_key)->toBe($key);
        if (count($keys) === 1) {
            throw new RuntimeException('expiry timeout');
        }
    });
    expect(fn () => startTestCheckout())->toThrow(RuntimeException::class);
    expect($attempt->refresh()->reservation_key)->not->toBeNull()->and(PaymentOperation::sole()->status)->toBe('unknown');
    app(ReconcilePaymentAction::class)->execute($attempt->id);
    $this->gateway->shouldReceive('createCheckout')->once()->andReturn(new HostedCheckout('cs_2', 'https://checkout.stripe.com/two', CarbonImmutable::now()->addHour()));
    expect(startTestCheckout()->sessionId)->toBe('cs_2')->and($keys[0])->toBe($keys[1]);
    expect($attempt->refresh()->reservation_key)->toBeNull();
});

it('does not release a failed Checkout when payment races its final verification', function (): void {
    $attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1299, 'currency' => 'JPY', 'origin' => 'checkout', 'state' => 'failed', 'reservation_key' => hash('sha256', 'order-1'), 'stripe_checkout_session_id' => 'cs_1', 'stripe_payment_intent_id' => 'pi_failed', 'capture_method' => 'automatic']);
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState('cs_1', 'pi_failed', 'acct_test', false, 1299, 'jpy', 'complete', 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->addHour()));
    $this->gateway->shouldReceive('payment')->andReturnUsing(function () use ($attempt) {
        $attempt->update(['state' => 'captured', 'captured_amount_minor' => 1299]);

        return new StripePaymentState('pi_failed', null, 'acct_test', false, 1299, 0, 0, 'jpy', 'requires_payment_method', 'automatic', 0);
    });
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect($attempt->refresh()->state)->toBe('captured')->and($attempt->reservation_key)->not->toBeNull()->and(PaymentAttempt::count())->toBe(1);
});

it('rejects concurrent identity changes before releasing a failed Checkout', function (): void {
    $attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1299, 'currency' => 'JPY', 'origin' => 'checkout', 'state' => 'failed', 'reservation_key' => hash('sha256', 'order-1'), 'stripe_checkout_session_id' => 'cs_1', 'stripe_payment_intent_id' => 'pi_failed', 'capture_method' => 'automatic']);
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState('cs_1', 'pi_failed', 'acct_test', false, 1299, 'jpy', 'complete', 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->addHour()));
    $this->gateway->shouldReceive('payment')->andReturnUsing(function () use ($attempt) {
        $attempt->update(['stripe_payment_intent_id' => 'pi_changed']);

        return new StripePaymentState('pi_failed', null, 'acct_test', false, 1299, 0, 0, 'jpy', 'requires_payment_method', 'automatic', 0);
    });
    $this->gateway->shouldReceive('createCheckout')->andReturn(new HostedCheckout('cs_2', 'https://checkout.stripe.com/two', CarbonImmutable::now()->addHour()));
    expect(fn () => startTestCheckout())->toThrow(DomainException::class);
    expect($attempt->refresh()->reservation_key)->not->toBeNull();
});
