<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Payments\Actions\RefundPaymentAction;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Tests\OwnerScopeTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;
use Nvl\Support\Ownership\Contracts\OwnershipContext;

uses(OwnerScopeTestCase::class);

it('reserves one native owner refund across independent admitted PostgreSQL workers', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires the dedicated native PostgreSQL process fixture.');
    }
    app()->bind(PaymentOrderProvider::class, fn () => new class implements PaymentOrderProvider
    {
        public function resolve(string $reference): OrderPaymentSnapshot
        {
            return new OrderPaymentSnapshot($reference, 'v1', 1000, 'EUR', 'Native order', null, true, owner: app(OwnershipContext::class)->requireOwner());
        }
    });
    app()->bind(PaymentManagementAccess::class, fn () => new class implements PaymentManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void {}
    });
    $attemptId = app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), fn () => PaymentAttempt::create([
        'order_reference' => 'native-race', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'attached',
        'state' => 'captured', 'captured_amount_minor' => 1000, 'capture_method' => 'automatic', 'stripe_payment_intent_id' => 'pi_native_race',
        'stripe_charge_id' => 'ch_native_race', 'stripe_account_id' => 'acct_native', 'stripe_livemode' => false,
    ])->id);
    $directory = sys_get_temp_dir().'/nvl-native-refund-'.Str::uuid();
    mkdir($directory);
    $waitFor = static function (Closure $condition): void {
        $deadline = microtime(true) + 15;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Native refund process barrier timed out.');
            }
            usleep(1000);
            clearstatcache();
        }
    };
    foreach (DB::getConnections() as $connection) {
        $connection->disconnect();
    }
    $children = [];
    try {
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork native refund worker.');
            }
            if ($pid === 0) {
                try {
                    app()->forgetScopedInstances();
                    $gateway = Mockery::mock(PaymentGateway::class);
                    $gateway->shouldReceive('payment')->andReturn(new StripePaymentState('pi_native_race', 'ch_native_race', 'acct_native', false, 1000, 1000, 0, 'eur', 'succeeded', 'automatic'));
                    $gateway->shouldReceive('refunds')->andReturnUsing(function () use ($directory, $worker, $waitFor): array {
                        touch($directory.'/read-'.$worker);
                        $waitFor(fn () => file_exists($directory.'/read-0') && file_exists($directory.'/read-1'));

                        return [];
                    });
                    $gateway->shouldReceive('refund')->andReturnUsing(function () use ($directory, $worker, $waitFor): StripeRefundState {
                        file_put_contents($directory.'/provider-calls', "refund\n", FILE_APPEND | LOCK_EX);
                        $waitFor(fn () => file_exists($directory.'/release'));

                        return new StripeRefundState('re_native_'.$worker, 'pi_native_race', 'ch_native_race', 'acct_native', false, 700, 'eur', 'succeeded', 'requested_by_customer');
                    });
                    app()->instance(PaymentGateway::class, $gateway);
                    try {
                        app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), fn () => app(RefundPaymentAction::class)->execute($attemptId, Actor::findOrFail(1), 700, 'requested_by_customer', null, (string) Str::uuid()));
                        file_put_contents($directory.'/result-'.$worker, 'accepted');
                    } catch (DomainException $exception) {
                        file_put_contents($directory.'/result-'.$worker, 'blocked');
                    }
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($directory.'/result-'.$worker, $exception::class.': '.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        $waitFor(fn () => file_exists($directory.'/result-0') || file_exists($directory.'/result-1'));
        touch($directory.'/release');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = [file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1')];
        sort($results);
        expect($results)->toBe(['accepted', 'blocked'])->and(file_get_contents($directory.'/provider-calls'))->toBe("refund\n");
        app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), function (): void {
            expect(PaymentRefund::count())->toBe(1)->and(PaymentRefund::sole()->amount_minor)->toBe(700)
                ->and(PaymentOperation::sole()->status)->toBe('completed');
        });
        app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function (): void {
            expect(PaymentRefund::count())->toBe(0)->and(PaymentOperation::count())->toBe(0);
        });
    } finally {
        touch($directory.'/release');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
