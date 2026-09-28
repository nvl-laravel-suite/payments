<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\StripeRefundState;

require_once __DIR__.'/../RefundTestCase.php';
uses(PaymentsSchemaTestCase::class);

it('atomically reserves only one refund when competing processes would exceed captured balance', function (string $driver): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl for real process concurrency.');
    }
    if ($driver === 'pgsql' && ! getenv('PAYMENTS_TEST_PG_DATABASE')) {
        $this->markTestSkipped('Set PAYMENTS_TEST_PG_DATABASE for the PostgreSQL concurrency contract.');
    }
    setupRefundHost();
    $directory = sys_get_temp_dir().'/payments-refund-'.Str::uuid();
    mkdir($directory);
    touch($directory.'/database.sqlite');
    $settings = $driver === 'sqlite'
        ? array_replace(config('database.connections.sqlite'), ['database' => $directory.'/database.sqlite', 'busy_timeout' => 5000])
        : array_replace(config('database.connections.pgsql'), ['host' => getenv('PAYMENTS_TEST_PG_HOST') ?: '127.0.0.1', 'port' => getenv('PAYMENTS_TEST_PG_PORT') ?: '5432', 'database' => getenv('PAYMENTS_TEST_PG_DATABASE'), 'username' => getenv('PAYMENTS_TEST_PG_USERNAME') ?: 'postgres', 'password' => getenv('PAYMENTS_TEST_PG_PASSWORD') ?: '']);
    config(['database.connections.refund_race' => $settings, 'payments.connection' => 'refund_race']);
    $migration = require __DIR__.'/../../database/migrations/payments/2026_09_28_000001_create_payments_tables.php';
    $migration->up();
    $attempt = createRefundAttempt();
    DB::purge('refund_race');
    $waitFor = static function (Closure $condition): void {
        $deadline = microtime(true) + 10;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Refund race barrier timed out.');
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
                throw new RuntimeException('Could not fork refund worker.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('refund_race');
                    $gateway = Mockery::mock(PaymentGateway::class);
                    $gateway->shouldReceive('payment')->andReturn(refundPaymentState());
                    $gateway->shouldReceive('refunds')->andReturnUsing(function () use ($directory, $worker, $waitFor): array {
                        touch($directory.'/read-'.$worker);
                        $waitFor(fn () => file_exists($directory.'/read-0') && file_exists($directory.'/read-1'));

                        return [];
                    });
                    $gateway->shouldReceive('refund')->andReturnUsing(function () use ($directory, $waitFor, $worker): StripeRefundState {
                        file_put_contents($directory.'/refunds', "refund\n", FILE_APPEND | LOCK_EX);
                        $waitFor(fn () => file_exists($directory.'/release'));

                        return remoteRefund('re_worker_'.$worker);
                    });
                    app()->instance(PaymentGateway::class, $gateway);
                    try {
                        refundPayment(attemptId: $attempt->id);
                        file_put_contents($directory.'/result-'.$worker, 'accepted');
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
        $waitFor(fn () => file_exists($directory.'/result-0') || file_exists($directory.'/result-1'));
        touch($directory.'/release');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = [file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1')];
        sort($results);
        expect($results)->toBe(['accepted', 'blocked'])->and(file_get_contents($directory.'/refunds'))->toBe("refund\n")
            ->and(PaymentRefund::count())->toBe(1)->and(PaymentRefund::sole()->amount_minor)->toBe(700)
            ->and(PaymentOperation::sole()->status)->toBe('completed')
            ->and(DB::connection('sqlite')->table(PaymentRefund::TABLE)->count())->toBe(0);
    } finally {
        touch($directory.'/release');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $migration->down();
        DB::purge('refund_race');
        config(['payments.connection' => null]);
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(['sqlite', 'pgsql']);
