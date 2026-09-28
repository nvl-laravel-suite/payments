<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Str;
use Nvl\Payments\Actions\CancelAuthorizationAction;
use Nvl\Payments\Actions\CapturePaymentAction;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\PaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;

require_once __DIR__.'/../PaymentsTestCase.php';
uses(PaymentsSchemaTestCase::class);

function authorizationState(string $status = 'requires_capture', int $captured = 0): StripePaymentState
{
    return new StripePaymentState('pi_auth', 'ch_auth', 'acct_test', false, 1200, $captured, 0, 'eur', $status, 'manual', $status === 'requires_capture' ? 1200 : 0, 'card');
}

function authorizationOperation(string $kind, string $attempt, string $uuid, int $amount = 700, string $actor = 'admin'): PaymentSnapshot
{
    return $kind === 'capture'
        ? app(CapturePaymentAction::class)->execute($attempt, new GenericUser(['id' => $actor]), $amount, $uuid)
        : app(CancelAuthorizationAction::class)->execute($attempt, new GenericUser(['id' => $actor]), $uuid);
}

beforeEach(function (): void {
    config(['payments.stripe.account_id' => 'acct_test']);
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $orders->shouldReceive('resolve')->with('order-1')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1200, 'EUR', 'Order', null, false));
    app()->instance(PaymentOrderProvider::class, $orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $operation, $order) => in_array($operation, ['capture', 'cancel_authorization'], true) && $order->reference === 'order-1');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway = Mockery::mock(PaymentGateway::class);
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'authorized', 'capture_method' => 'manual', 'stripe_payment_intent_id' => 'pi_auth', 'stripe_charge_id' => 'ch_auth', 'reservation_key' => 'reserved-order']);
    $this->uuid = (string) Str::uuid();
});

it('confirms one final capture and returns the persisted snapshot on an identical retry', function (int $amount): void {
    $this->gateway->shouldReceive('payment')->once()->with('pi_auth')->andReturn(authorizationState());
    $this->gateway->shouldReceive('capture')->once()->with('pi_auth', $amount, 'payments:capture:'.$this->uuid)->andReturnUsing(function () use ($amount): StripePaymentState {
        expect(PaymentOperation::sole()->status)->toBe('reserved')
            ->and(PaymentOperation::sole()->payment_attempt_id)->toBe($this->attempt->id)
            ->and((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0);

        return authorizationState('succeeded', $amount);
    });
    $result = authorizationOperation('capture', $this->attempt->id, $this->uuid, $amount);
    $again = authorizationOperation('capture', $this->attempt->id, $this->uuid, $amount);
    expect($result->status)->toBe('captured')->and($result->capturedAmountMinor)->toBe($amount)
        ->and($again)->toEqual($result)->and(PaymentOperation::sole()->status)->toBe('completed');
    expect(fn () => authorizationOperation('capture', $this->attempt->id, (string) Str::uuid(), 500))->toThrow(DomainException::class);
})->with([700, 1200]);

it('cancels an authorization and releases its checkout reservation', function (): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(authorizationState());
    $this->gateway->shouldReceive('cancel')->once()->with('pi_auth', 'payments:cancel_authorization:'.$this->uuid)->andReturnUsing(function (): StripePaymentState {
        expect(PaymentOperation::sole()->status)->toBe('reserved')->and((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0);

        return authorizationState('canceled');
    });
    $result = authorizationOperation('cancel_authorization', $this->attempt->id, $this->uuid);
    expect($result->status)->toBe('canceled')->and($this->attempt->refresh()->reservation_key)->toBeNull()
        ->and(authorizationOperation('cancel_authorization', $this->attempt->id, $this->uuid))->toEqual($result);
});

it('denies financial operations by default before writes or remote access', function (string $kind): void {
    app()->instance(PaymentManagementAccess::class, new DenyPaymentManagementAccess);
    expect(fn () => authorizationOperation($kind, $this->attempt->id, $this->uuid))->toThrow(AuthorizationException::class);
    expect(PaymentOperation::count())->toBe(0);
})->with(['capture', 'cancel_authorization']);

it('rejects attempts that are not locally authorized', function (string $state, string $kind): void {
    $this->attempt->update(['state' => $state]);
    expect(fn () => authorizationOperation($kind, $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::count())->toBe(0);
})->with(['open', 'processing', 'canceled', 'expired', 'captured', 'payment_exception'])->with(['capture', 'cancel_authorization']);

it('rereads Stripe and rejects an expired or otherwise unavailable authorization', function (string $status, string $kind): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(authorizationState($status));
    expect(fn () => authorizationOperation($kind, $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::where('status', 'completed')->count())->toBe(0);
})->with(['canceled', 'succeeded', 'processing', 'requires_action'])->with(['capture', 'cancel_authorization']);

it('leaves timeouts unresolved and blocks same or different financial operations', function (string $kind): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(authorizationState());
    $this->gateway->shouldReceive($kind === 'capture' ? 'capture' : 'cancel')->once()->andThrow(new RuntimeException('timeout'));
    expect(fn () => authorizationOperation($kind, $this->attempt->id, $this->uuid))->toThrow(RuntimeException::class, 'timeout');
    expect(PaymentOperation::sole()->status)->toBe('unknown');
    expect(fn () => authorizationOperation($kind, $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
    foreach (['capture', 'cancel_authorization'] as $retryKind) {
        expect(fn () => authorizationOperation($retryKind, $this->attempt->id, (string) Str::uuid()))->toThrow(DomainException::class);
    }
    expect(PaymentOperation::count())->toBe(1);
})->with(['capture', 'cancel_authorization']);

it('rejects reused UUIDs with different amounts actors or kinds', function (): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(authorizationState());
    $this->gateway->shouldReceive('capture')->once()->andReturn(authorizationState('succeeded', 700));
    authorizationOperation('capture', $this->attempt->id, $this->uuid);
    expect(fn () => authorizationOperation('capture', $this->attempt->id, $this->uuid, 800))->toThrow(DomainException::class);
    expect(fn () => authorizationOperation('capture', $this->attempt->id, $this->uuid, 700, 'other'))->toThrow(DomainException::class);
    expect(fn () => authorizationOperation('cancel_authorization', $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
});

it('blocks competing operations while the capture is in flight', function (): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(authorizationState());
    $this->gateway->shouldReceive('capture')->once()->andReturnUsing(function (): StripePaymentState {
        expect(fn () => authorizationOperation('capture', $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
        expect(fn () => authorizationOperation('cancel_authorization', $this->attempt->id, (string) Str::uuid()))->toThrow(DomainException::class);

        return authorizationState('succeeded', 700);
    });
    expect(authorizationOperation('capture', $this->attempt->id, $this->uuid)->capturedAmountMinor)->toBe(700);
});

it('rejects invalid capture amounts before a remote mutation', function (int $amount): void {
    expect(fn () => authorizationOperation('capture', $this->attempt->id, $this->uuid, $amount))->toThrow(InvalidArgumentException::class);
    expect(PaymentOperation::count())->toBe(0);
})->with([0, -1, 1201]);

it('rejects unsupported or incomplete remote authorization facts', function (?int $capturable, ?string $method, string $captureMethod, int $captured, ?string $partial, string $kind): void {
    $remote = new StripePaymentState('pi_auth', 'ch_auth', 'acct_test', false, 1200, $captured, 0, 'eur', 'requires_capture', $captureMethod, $capturable, $method, $partial);
    $this->gateway->shouldReceive('payment')->once()->andReturn($remote);
    expect(fn () => authorizationOperation($kind, $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::where('status', 'completed')->count())->toBe(0);
})->with([[null, 'card', 'manual', 0, null], [800, 'card', 'manual', 0, null], [1200, null, 'manual', 0, null], [1200, 'ideal', 'manual', 0, null], [1200, 'card', 'automatic', 0, null], [1200, 'card', 'manual', 100, null], [1200, 'card', 'manual', 0, 'partially_authorized']])->with(['capture', 'cancel_authorization']);

it('keeps an unexpected remote mutation result unresolved', function (string $kind): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(authorizationState());
    $this->gateway->shouldReceive($kind === 'capture' ? 'capture' : 'cancel')->once()->andReturn(authorizationState());
    expect(fn () => authorizationOperation($kind, $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::sole()->status)->toBe('unknown')->and($this->attempt->refresh()->captured_amount_minor)->toBe(0);
})->with(['capture', 'cancel_authorization']);

it('rejects missing PaymentIntent identity and disabled Payments before remote access', function (bool $enabled, ?string $intent): void {
    config(['payments.enabled' => $enabled]);
    $this->attempt->update(['stripe_payment_intent_id' => $intent]);
    expect(fn () => authorizationOperation('capture', $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::count())->toBe(0);
})->with([[false, 'pi_auth'], [true, null], [true, 'ch_auth']]);

it('rejects an outer transaction before reserving or calling Stripe', function (): void {
    $connection = $this->attempt->getConnection();
    $connection->beginTransaction();
    try {
        expect(fn () => authorizationOperation('capture', $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
        expect(PaymentOperation::count())->toBe(0);
    } finally {
        $connection->rollBack();
    }
});

it('rejects mismatched Stripe identity before a financial mutation', function (): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(new StripePaymentState('pi_other', 'ch_auth', 'acct_test', false, 1200, 0, 0, 'eur', 'requires_capture', 'manual', 1200, 'card'));
    expect(fn () => authorizationOperation('capture', $this->attempt->id, $this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::sole()->status)->toBe('unknown');
});

it('rejects a UUID assigned to another attempt', function (): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(authorizationState());
    $this->gateway->shouldReceive('capture')->once()->andReturn(authorizationState('succeeded', 700));
    authorizationOperation('capture', $this->attempt->id, $this->uuid);
    $other = $this->attempt->replicate(['reservation_key', 'stripe_payment_intent_id', 'stripe_charge_id']);
    $other->save();
    expect(fn () => authorizationOperation('capture', $other->id, $this->uuid))->toThrow(DomainException::class);
    expect(PaymentOperation::count())->toBe(1);
});
