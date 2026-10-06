<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Payments\Actions\AttachExistingPaymentAction;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Events\PaymentStateChanged;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\DenyExistingPaymentOwnership;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Services\DenyPaymentOrderProvider;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\PaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;

require_once __DIR__.'/../PaymentsTestCase.php';
uses(PaymentsSchemaTestCase::class);

function attachmentState(?string $intent = 'pi_existing', ?string $charge = 'ch_existing', string $account = 'acct_test', bool $live = false, int $amount = 1200, int $captured = 1200, int $refunded = 0, string $currency = 'eur', string $status = 'succeeded'): StripePaymentState
{
    return new StripePaymentState($intent, $charge, $account, $live, $amount, $captured, $refunded, $currency, $status, 'automatic');
}

function attachPayment(string $uuid, string $reference = 'pi_existing', string $actor = 'admin', string $order = 'order-1'): PaymentSnapshot
{
    return app(AttachExistingPaymentAction::class)->execute($order, new GenericUser(['id' => $actor]), $reference, $uuid);
}

beforeEach(function (): void {
    config(['nvl-payments.stripe.account_id' => 'acct_test', 'nvl-payments.allowed_currencies' => ['EUR']]);
    $this->orders = Mockery::mock(PaymentOrderProvider::class);
    $this->orders->shouldReceive('resolve')->with('order-1')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1200, 'EUR', 'Order', null, false))->byDefault();
    app()->instance(PaymentOrderProvider::class, $this->orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $operation, $order) => $operation === 'attach_existing' && $order->reference === 'order-1');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->ownership = Mockery::mock(ExistingPaymentOwnership::class);
    $this->ownership->shouldReceive('assertOwned')->withArgs(fn ($order, $payment) => $order->reference === 'order-1' && $payment->chargeId === 'ch_existing')->byDefault();
    app()->instance(ExistingPaymentOwnership::class, $this->ownership);
    $this->gateway = Mockery::mock(PaymentGateway::class);
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->uuid = (string) Str::uuid();
});

it('attaches canonical payment facts and replays its completed operation without remote access', function (string $reference, ?string $intent): void {
    Event::listen(PaymentStateChanged::class, function (): void {
        expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0)
            ->and(PaymentOperation::sole()->status)->toBe('completed');
    });
    $this->gateway->shouldReceive('resolveExisting')->once()->with($reference)->andReturnUsing(function () use ($intent): StripePaymentState {
        expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0)
            ->and(PaymentOperation::sole()->status)->toBe('reserved');

        return attachmentState(intent: $intent, refunded: 200);
    });
    $result = attachPayment($this->uuid, $reference);
    expect($result->origin)->toBe('attached')->and($result->status)->toBe('partially_refunded')
        ->and($result->paymentIntentId)->toBe($intent)->and($result->chargeId)->toBe('ch_existing')
        ->and($result->capturedAmountMinor)->toBe(1200)->and($result->refundedAmountMinor)->toBe(200)
        ->and($result->lastSyncedAt)->not->toBeNull()->and($result->orderReference)->toBe('order-1')
        ->and(attachPayment($this->uuid, $reference))->toEqual($result)->and(PaymentAttempt::count())->toBe(1);
})->with([['pi_existing', 'pi_existing'], ['ch_existing', 'pi_existing'], ['txn_existing', 'pi_existing'], ['ch_existing', null]]);

it('requires each host contract and never treats matching amount as ownership', function (string $contract, string $implementation): void {
    app()->instance($contract, new $implementation);
    if ($contract === ExistingPaymentOwnership::class) {
        $this->gateway->shouldReceive('resolveExisting')->once()->andReturn(attachmentState());
    }
    expect(fn () => attachPayment($this->uuid))->toThrow(AuthorizationException::class);
    expect(PaymentAttempt::count())->toBe(0)->and(PaymentOperation::where('status', 'completed')->count())->toBe(0);
})->with([[PaymentOrderProvider::class, DenyPaymentOrderProvider::class], [PaymentManagementAccess::class, DenyPaymentManagementAccess::class], [ExistingPaymentOwnership::class, DenyExistingPaymentOwnership::class]]);

it('rejects mismatched or invalid canonical financial facts', function (StripePaymentState $state): void {
    $this->gateway->shouldReceive('resolveExisting')->once()->andReturn($state);
    expect(fn () => attachPayment($this->uuid))->toThrow(DomainException::class);
    expect(PaymentAttempt::count())->toBe(0)->and(PaymentOperation::sole()->status)->toBe('unknown');
})->with([
    'account' => fn () => attachmentState(account: 'acct_wrong'),
    'mode' => fn () => attachmentState(live: true),
    'amount' => fn () => attachmentState(amount: 1300),
    'currency' => fn () => attachmentState(currency: 'usd'),
    'overcapture' => fn () => attachmentState(captured: 1300),
    'overrefund' => fn () => attachmentState(refunded: 1300),
    'negative capture' => fn () => attachmentState(captured: -1),
    'no identity' => fn () => attachmentState(intent: null, charge: null),
    'wrong intent' => fn () => attachmentState(intent: 'pi_other'),
    'unknown status' => fn () => attachmentState(status: 'unrecognized'),
]);

it('rejects a host order mismatch and a failed ownership proof', function (bool $wrongOrder): void {
    if ($wrongOrder) {
        $this->orders->shouldReceive('resolve')->with('wrong-order')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1200, 'EUR', 'Order', null, false));
    } else {
        $this->gateway->shouldReceive('resolveExisting')->once()->andReturn(attachmentState());
        $this->ownership->shouldReceive('assertOwned')->andThrow(new AuthorizationException('Wrong host order.'));
    }
    expect(fn () => attachPayment($this->uuid, order: $wrongOrder ? 'wrong-order' : 'order-1'))->toThrow($wrongOrder ? DomainException::class : AuthorizationException::class);
    expect(PaymentAttempt::count())->toBe(0);
})->with([true, false]);

it('preserves rejection of refund and payout balance transactions', function (string $reference): void {
    $this->gateway->shouldReceive('resolveExisting')->with($reference)->andThrow(new InvalidArgumentException('Balance transaction does not refer to a payment.'));
    expect(fn () => attachPayment($this->uuid, $reference))->toThrow(InvalidArgumentException::class);
    expect(PaymentAttempt::count())->toBe(0);
})->with(['txn_refund', 'txn_payout']);

it('rejects an already attached canonical intent or charge including competing attachment completion', function (?string $intent, ?string $charge): void {
    $this->gateway->shouldReceive('resolveExisting')->once()->andReturnUsing(function () use ($intent, $charge): StripePaymentState {
        PaymentAttempt::create(['order_reference' => 'other-order', 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'attached', 'state' => 'captured', 'stripe_payment_intent_id' => $intent, 'stripe_charge_id' => $charge]);

        return attachmentState();
    });
    expect(fn () => attachPayment($this->uuid))->toThrow(DomainException::class);
    expect(PaymentAttempt::count())->toBe(1)->and(PaymentAttempt::sole()->order_reference)->toBe('other-order');
})->with([['pi_existing', null], [null, 'ch_existing']]);

it('binds the operation UUID to the original reference and actor', function (): void {
    $this->gateway->shouldReceive('resolveExisting')->once()->andReturn(attachmentState());
    attachPayment($this->uuid);
    expect(fn () => attachPayment($this->uuid, 'ch_existing'))->toThrow(DomainException::class);
    expect(fn () => attachPayment($this->uuid, actor: 'other-admin'))->toThrow(DomainException::class);
    expect(PaymentAttempt::count())->toBe(1);
});

it('blocks an in-flight operation and preserves uncertain reads for reconciliation', function (): void {
    $this->gateway->shouldReceive('resolveExisting')->once()->andReturnUsing(function (): never {
        expect(fn () => attachPayment($this->uuid))->toThrow(DomainException::class);
        throw new RuntimeException('timeout');
    });
    expect(fn () => attachPayment($this->uuid))->toThrow(RuntimeException::class, 'timeout');
    expect(fn () => attachPayment($this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::sole()->status)->toBe('unknown')->and(PaymentAttempt::count())->toBe(0);
});

it('rejects outer transactions before reserving an operation', function (): void {
    $connection = (new PaymentAttempt)->getConnection();
    $connection->beginTransaction();
    try {
        expect(fn () => attachPayment($this->uuid))->toThrow(DomainException::class);
        expect(PaymentOperation::count())->toBe(0);
    } finally {
        $connection->rollBack();
    }
});

it('uses the configured Payments connection for its journal attempt and after-commit event', function (): void {
    config(['database.connections.payments_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'nvl-payments.connection' => 'payments_test']);
    $migration = require __DIR__.'/../../database/migrations/payments/2026_09_28_000001_nvl_payments_create_payments_tables.php';
    $migration->up();
    Event::listen(PaymentStateChanged::class, function (): void {
        expect((new PaymentAttempt)->getConnection()->getName())->toBe('payments_test')
            ->and((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0)
            ->and(PaymentOperation::sole()->status)->toBe('completed');
    });
    $this->gateway->shouldReceive('resolveExisting')->once()->andReturn(attachmentState());
    attachPayment($this->uuid);
    expect(PaymentAttempt::count())->toBe(1)->and(PaymentOperation::count())->toBe(1)
        ->and(DB::connection('sqlite')->table(PaymentsTables::Attempts)->count())->toBe(0)->and(DB::connection('sqlite')->table(PaymentsTables::Operations)->count())->toBe(0);
});

it('rejects invalid operation and reference inputs before durable reservation', function (string $reference, string $uuid, string $actor): void {
    expect(fn () => attachPayment($uuid, $reference, $actor))->toThrow(InvalidArgumentException::class);
    expect(PaymentOperation::count())->toBe(0)->and(PaymentAttempt::count())->toBe(0);
})->with([['re_existing', '019941a2-ef00-7000-8000-000000000001', 'admin'], ['pi_existing', 'invalid', 'admin'], ['pi_existing', '019941a2-ef00-7000-8000-000000000001', '']]);

it('rejects disabled Payments and currencies outside the host allowlist', function (bool $enabled, array $currencies): void {
    config(['nvl-payments.enabled' => $enabled, 'nvl-payments.allowed_currencies' => $currencies]);
    expect(fn () => attachPayment($this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::count())->toBe(0);
})->with([[false, ['EUR']], [true, ['USD']]]);

it('binds an operation to the trusted order revision', function (): void {
    $this->gateway->shouldReceive('resolveExisting')->once()->andReturn(attachmentState());
    attachPayment($this->uuid);
    $this->orders->shouldReceive('resolve')->with('order-1')->andReturn(new OrderPaymentSnapshot('order-1', 'v2', 1200, 'EUR', 'Order', null, false));
    expect(fn () => attachPayment($this->uuid))->toThrow(DomainException::class);
    expect(PaymentAttempt::count())->toBe(1);
});
