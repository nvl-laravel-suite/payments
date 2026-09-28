<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Payments\Actions\ProcessPaymentsWebhookAction;
use Nvl\Payments\Actions\ReconcilePaymentAction;
use Nvl\Payments\Actions\RecoverCheckoutAction;
use Nvl\Payments\Actions\ResolvePaymentExceptionAction;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Events\PaymentStateChanged;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;
use Stripe\Event as StripeEvent;

require_once __DIR__.'/../ReconciliationTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    setupRefundHost();
    $this->attempt = createRefundAttempt();
    $this->gateway = Mockery::mock(PaymentGateway::class);
    app()->instance(PaymentGateway::class, $this->gateway);
});

it('repairs a missed webhook and imports Dashboard refunds for an attached payment', function (): void {
    $this->attempt->update(['state' => 'processing', 'captured_amount_minor' => 0]);
    $this->gateway->shouldReceive('payment')->andReturnUsing(function () {
        expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0);

        return refundPaymentState(700);
    });
    $this->gateway->shouldReceive('refunds')->andReturn([remoteRefund('re_dashboard')]);
    $snapshot = app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($snapshot->status)->toBe('partially_refunded')->and($snapshot->refundedAmountMinor)->toBe(700)
        ->and(PaymentRefund::sole()->stripe_refund_id)->toBe('re_dashboard')->and(PaymentOperation::sole()->status)->toBe('completed');
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect(PaymentRefund::count())->toBe(1);
});

it('repairs succeeded refunds returning to action and then failure', function (string $final): void {
    $this->attempt->update(['refunded_amount_minor' => 700, 'state' => 'partially_refunded']);
    $refund = reconciliationRefund($this->attempt, 're_test', 'succeeded');
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([remoteRefund(status: 'requires_action')], [remoteRefund(status: $final)]);
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($refund->refresh()->status)->toBe('requires_action')->and($this->attempt->refresh()->refunded_amount_minor)->toBe(0);
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($refund->refresh()->status)->toBe($final)->and($this->attempt->refresh()->state)->toBe('captured');
})->with(['failed', 'canceled']);

it('never clears a missing ID refund reservation because the remote list is empty', function (): void {
    $refund = reconciliationRefund($this->attempt, null);
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($refund->refresh()->status)->toBe('reserved')->and(PaymentOperation::sole()->status)->toBe('unknown');
});

it('closes known refund journal outcomes from authoritative refund identity', function (): void {
    $refund = reconciliationRefund($this->attempt, 're_test');
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([remoteRefund()]);
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($refund->refresh()->status)->toBe('succeeded')->and(PaymentOperation::sole()->status)->toBe('completed');
});

it('resolves confirmed authorization outcomes while retaining unconfirmed ones', function (string $kind, string $remoteStatus, string $expected): void {
    $this->attempt->update(['state' => 'authorized', 'capture_method' => 'manual', 'captured_amount_minor' => 0]);
    $operation = reconciliationOperation($this->attempt, $kind);
    $this->gateway->shouldReceive('payment')->andReturn(new StripePaymentState('pi_refund', 'ch_refund', 'acct_test', false, 1200, $remoteStatus === 'succeeded' ? 1200 : 0, 0, 'eur', $remoteStatus, 'manual', 0));
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($operation->refresh()->status)->toBe($expected);
})->with([['capture', 'succeeded', 'completed'], ['cancel_authorization', 'canceled', 'completed'], ['capture', 'requires_capture', 'unknown'], ['cancel_authorization', 'requires_capture', 'unknown']]);

it('preserves local facts on a remote timeout', function (): void {
    $operation = reconciliationOperation($this->attempt, 'capture');
    $this->gateway->shouldReceive('payment')->andThrow(new RuntimeException('timeout'));
    expect(fn () => app(ReconcilePaymentAction::class)->execute($this->attempt->id))->toThrow(RuntimeException::class);
    expect($operation->refresh()->status)->toBe('unknown')->and($this->attempt->refresh()->last_synced_at)->toBeNull();
});

it('releases a stale Checkout only on confirmed unpaid expiry', function (): void {
    $this->attempt->update(['origin' => 'checkout', 'state' => 'unknown', 'captured_amount_minor' => 0, 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'stripe_checkout_session_id' => 'cs_old', 'reservation_key' => 'reserved']);
    $operation = reconciliationOperation($this->attempt, 'expire_checkout');
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState('cs_old', null, 'acct_test', false, 1200, 'eur', 'expired', 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->subDay()));
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($this->attempt->refresh()->reservation_key)->toBeNull()->and($this->attempt->state)->toBe('expired')->and($operation->refresh()->status)->toBe('completed');
});

it('keeps a missing Session ID blocked indefinitely', function (): void {
    $this->attempt->update(['origin' => 'checkout', 'state' => 'reserved', 'captured_amount_minor' => 0, 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'reservation_key' => 'reserved', 'expires_at' => now()->subDays(10)]);
    $operation = reconciliationOperation($this->attempt, 'checkout');
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($this->attempt->refresh()->reservation_key)->toBe('reserved')->and($operation->refresh()->status)->toBe('unknown');
});

it('recovers a lost Checkout only with matching operation metadata', function (bool $matches): void {
    $this->attempt->update(['origin' => 'checkout', 'state' => 'reserved', 'captured_amount_minor' => 0, 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'reservation_key' => 'reserved']);
    $operation = reconciliationOperation($this->attempt, 'checkout');
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $kind) => $kind === 'recover_checkout');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState('cs_recovered', null, 'acct_test', false, 1200, 'eur', 'open', 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->addHour(), $matches ? $operation->idempotency_key : null, 'https://checkout.stripe.com/recovered'));
    $recover = fn () => app(RecoverCheckoutAction::class)->execute($this->attempt->id, 'cs_recovered', new GenericUser(['id' => 'admin']));
    if (! $matches) {
        expect($recover)->toThrow(DomainException::class);
        expect($this->attempt->refresh()->stripe_checkout_session_id)->toBeNull()->and($operation->refresh()->status)->toBe('unknown');
    } else {
        $recover();
        expect($this->attempt->refresh()->checkout_url)->toBe('https://checkout.stripe.com/recovered')->and($operation->refresh()->status)->toBe('completed');
    }
})->with([true, false]);

it('rejects a concurrent refund change made during remote reads', function (): void {
    $refund = reconciliationRefund($this->attempt, 're_test', 'pending');
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturnUsing(function () use ($refund) {
        $refund->update(['status' => 'succeeded']);

        return [remoteRefund(status: 'pending')];
    });
    expect(fn () => app(ReconcilePaymentAction::class)->execute($this->attempt->id))->toThrow(DomainException::class);
    expect($refund->refresh()->status)->toBe('succeeded');
});

it('keeps newer webhook facts when a remote read completes late', function (): void {
    $this->gateway->shouldReceive('payment')->andReturnUsing(function () {
        $this->attempt->update(['refunded_amount_minor' => 700, 'state' => 'partially_refunded']);

        return refundPaymentState();
    });
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    expect(fn () => app(ReconcilePaymentAction::class)->execute($this->attempt->id))->toThrow(DomainException::class);
    expect($this->attempt->refresh()->refunded_amount_minor)->toBe(700);
});

it('rejects uncorrelated Dashboard refund facts atomically', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([new StripeRefundState('re_wrong', 'pi_other', 'ch_other', 'acct_test', false, 700, 'eur', 'succeeded', 'requested_by_customer')]);
    expect(fn () => app(ReconcilePaymentAction::class)->execute($this->attempt->id))->toThrow(DomainException::class);
    expect(PaymentRefund::count())->toBe(0)->and(DB::connection('sqlite')->table(PaymentAttempt::TABLE)->where('id', $this->attempt->id)->value('refunded_amount_minor'))->toBe(0);
});

it('requires recovery authorization before any Stripe lookup', function (): void {
    app()->bind(PaymentManagementAccess::class, DenyPaymentManagementAccess::class);
    expect(fn () => app(RecoverCheckoutAction::class)->execute($this->attempt->id, 'cs_recovery', new GenericUser(['id' => 'admin'])))->toThrow(AuthorizationException::class);
});

it('recovers a paid or expired Session with exact operation proof', function (string $status): void {
    $this->attempt->update(['origin' => 'checkout', 'state' => 'reserved', 'captured_amount_minor' => 0, 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'reservation_key' => 'reserved']);
    $operation = reconciliationOperation($this->attempt, 'checkout');
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $kind) => $kind === 'recover_checkout');
    app()->instance(PaymentManagementAccess::class, $access);
    $paid = $status === 'complete';
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState('cs_recovery', $paid ? 'pi_refund' : null, 'acct_test', false, 1200, 'eur', $status, $paid ? 'paid' : 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->subDay(), $operation->idempotency_key));
    if ($paid) {
        $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
        $this->gateway->shouldReceive('refunds')->andReturn([]);
    }
    $result = app(RecoverCheckoutAction::class)->execute($this->attempt->id, 'cs_recovery', new GenericUser(['id' => 'admin']));
    expect($result->status)->toBe($paid ? 'captured' : 'expired')->and($operation->refresh()->status)->toBe('completed');
    expect($this->attempt->refresh()->reservation_key)->toBe($paid ? 'reserved' : null);
})->with(['complete', 'expired']);

it('never releases an open Session just because its local expiry passed', function (): void {
    $this->attempt->update(['origin' => 'checkout', 'state' => 'unknown', 'captured_amount_minor' => 0, 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'stripe_checkout_session_id' => 'cs_old', 'reservation_key' => 'reserved', 'expires_at' => now()->subDay()]);
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState('cs_old', null, 'acct_test', false, 1200, 'eur', 'open', 'unpaid', 'order-1', 'v1', CarbonImmutable::now()->subDay()));
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($this->attempt->refresh()->reservation_key)->toBe('reserved');
});

it('rejects reconciliation within a host transaction before Stripe reads', function (): void {
    $this->attempt->getConnection()->transaction(function (): void {
        expect(fn () => app(ReconcilePaymentAction::class)->execute($this->attempt->id))->toThrow(DomainException::class);
    });
});

it('reconciles on the configured connection without changing default storage', function (): void {
    config(['database.connections.payments_secondary' => ['driver' => 'sqlite', 'database' => ':memory:'], 'payments.connection' => 'payments_secondary']);
    $migration = require __DIR__.'/../../database/migrations/payments/2026_09_28_000001_create_payments_tables.php';
    $migration->up();
    $secondary = createRefundAttempt();
    $this->gateway->shouldReceive('payment')->andReturnUsing(function () {
        expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0);

        return refundPaymentState(700);
    });
    $this->gateway->shouldReceive('refunds')->andReturn([remoteRefund()]);
    app(ReconcilePaymentAction::class)->execute($secondary->id);
    expect($secondary->refresh()->refunded_amount_minor)->toBe(700)
        ->and(DB::connection('sqlite')->table(PaymentAttempt::TABLE)->where('id', $this->attempt->id)->value('refunded_amount_minor'))->toBe(0);
});

it('rejects altered Checkout recovery facts before attachment', function (string $field, mixed $value): void {
    $this->attempt->update(['origin' => 'checkout', 'state' => 'reserved', 'captured_amount_minor' => 0, 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'reservation_key' => 'reserved']);
    $operation = reconciliationOperation($this->attempt, 'checkout');
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    $fields = ['sessionId' => 'cs_recovery', 'paymentIntentId' => null, 'accountId' => 'acct_test', 'livemode' => false, 'amountMinor' => 1200, 'currency' => 'eur', 'status' => 'expired', 'paymentStatus' => 'unpaid', 'orderReference' => 'order-1', 'orderRevision' => 'v1', 'expiresAt' => CarbonImmutable::now()->subDay(), 'operationKey' => $operation->idempotency_key];
    $fields[$field] = $value;
    $this->gateway->shouldReceive('checkout')->andReturn(new StripeCheckoutState(...$fields));
    expect(fn () => app(RecoverCheckoutAction::class)->execute($this->attempt->id, 'cs_recovery', new GenericUser(['id' => 'admin'])))->toThrow(DomainException::class);
    expect($this->attempt->refresh()->stripe_checkout_session_id)->toBeNull()->and($this->attempt->reservation_key)->toBe('reserved')->and($operation->refresh()->status)->toBe('unknown');
})->with([['sessionId', 'cs_other'], ['accountId', 'acct_other'], ['livemode', true], ['amountMinor', 1199], ['currency', 'usd'], ['orderReference', 'other'], ['orderRevision', 'v2'], ['operationKey', 'other']]);

it('recovers a lost refund response by exact operation metadata without double reservation', function (): void {
    $refund = reconciliationRefund($this->attempt, null);
    $operation = PaymentOperation::findOrFail($refund->payment_operation_id);
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([new StripeRefundState('re_recovered', 'pi_refund', 'ch_refund', 'acct_test', false, 700, 'eur', 'succeeded', 'requested_by_customer', $operation->idempotency_key)]);
    $result = app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($result->refundableAmountMinor)->toBe(500)->and(PaymentRefund::count())->toBe(1)
        ->and($refund->refresh()->stripe_refund_id)->toBe('re_recovered')->and($operation->refresh()->status)->toBe('completed');
    app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect(PaymentRefund::count())->toBe(1);
});

it('rejects mismatched refund recovery proof atomically', function (string $field, mixed $value): void {
    $refund = reconciliationRefund($this->attempt, null);
    $operation = PaymentOperation::findOrFail($refund->payment_operation_id);
    $fields = ['refundId' => 're_recovered', 'paymentIntentId' => 'pi_refund', 'chargeId' => 'ch_refund', 'accountId' => 'acct_test', 'livemode' => false, 'amountMinor' => 700, 'currency' => 'eur', 'status' => 'succeeded', 'reason' => 'requested_by_customer', 'operationKey' => $operation->idempotency_key];
    $fields[$field] = $value;
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([new StripeRefundState(...$fields)]);
    expect(fn () => app(ReconcilePaymentAction::class)->execute($this->attempt->id))->toThrow(DomainException::class);
    expect($refund->refresh()->stripe_refund_id)->toBeNull()->and($operation->refresh()->status)->toBe('unknown')->and(PaymentRefund::count())->toBe(1);
})->with([['operationKey', 'other'], ['paymentIntentId', 'pi_other'], ['chargeId', 'ch_other'], ['accountId', 'acct_other'], ['livemode', true], ['amountMinor', 600], ['currency', 'usd'], ['reason', 'duplicate']]);

it('keeps financially confirmed exception facts visible without accepting the order revision', function (): void {
    $this->attempt->update(['state' => 'payment_exception', 'captured_amount_minor' => 0, 'stripe_charge_id' => null, 'reservation_key' => 'held']);
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([remoteRefund()]);
    $result = app(ReconcilePaymentAction::class)->execute($this->attempt->id);
    expect($result->status)->toBe('payment_exception')->and($result->capturedAmountMinor)->toBe(1200)
        ->and($result->refundedAmountMinor)->toBe(700)->and($result->refundableAmountMinor)->toBe(500)
        ->and($this->attempt->refresh()->reservation_key)->toBe('held')->and($this->attempt->order_revision)->toBe('v1');
});

it('requires explicit host admission to accept an exception', function (): void {
    app()->bind(PaymentManagementAccess::class, DenyPaymentManagementAccess::class);
    $this->attempt->update(['state' => 'payment_exception']);
    expect(fn () => app(ResolvePaymentExceptionAction::class)->execute($this->attempt->id, new GenericUser(['id' => 'admin']), (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(PaymentOperation::count())->toBe(0);
});

it('accepts exception money with a durable actor journal and exact replay', function (): void {
    $this->attempt->update(['state' => 'payment_exception', 'reservation_key' => 'held']);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $kind) => $kind === 'resolve_payment_exception');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway->shouldReceive('payment')->once()->andReturn(refundPaymentState());
    $uuid = (string) Str::uuid();
    $action = app(ResolvePaymentExceptionAction::class);
    $result = $action->execute($this->attempt->id, new GenericUser(['id' => 'admin']), $uuid);
    expect($result->status)->toBe('captured')->and($this->attempt->refresh()->order_revision)->toBe('v1')
        ->and($this->attempt->reservation_key)->toBe('held')->and(PaymentOperation::sole()->actor_reference)->toBe('admin')
        ->and(PaymentOperation::sole()->type)->toBe('resolve_payment_exception')->and(PaymentOperation::sole()->status)->toBe('completed');
    expect($action->execute($this->attempt->id, new GenericUser(['id' => 'admin']), $uuid)->status)->toBe('captured');
    expect(PaymentOperation::count())->toBe(1);
    expect(fn () => $action->execute($this->attempt->id, new GenericUser(['id' => 'other']), $uuid))->toThrow(DomainException::class);
});

it('retains an exception and emits money changes after commit through duplicate webhooks', function (): void {
    $this->attempt->update(['state' => 'payment_exception', 'captured_amount_minor' => 0, 'stripe_charge_id' => null, 'reservation_key' => 'held']);
    Event::fake([PaymentStateChanged::class]);
    $this->gateway->shouldReceive('payment')->once()->andReturn(refundPaymentState());
    $event = StripeEvent::constructFrom(['id' => 'evt_exception', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'account' => null, 'data' => ['object' => ['id' => 'pi_refund']]]);
    app(ProcessPaymentsWebhookAction::class)->execute($event);
    app(ProcessPaymentsWebhookAction::class)->execute($event);
    expect($this->attempt->refresh()->state)->toBe('payment_exception')->and($this->attempt->captured_amount_minor)->toBe(1200)
        ->and($this->attempt->stripe_charge_id)->toBe('ch_refund')->and($this->attempt->reservation_key)->toBe('held');
    Event::assertDispatchedTimes(PaymentStateChanged::class, 1);
    Event::assertDispatched(PaymentStateChanged::class, fn ($event) => $event->newState === 'payment_exception');
});

it('does not correlate an unknown refund by equal amount without operation proof', function (): void {
    $refund = reconciliationRefund($this->attempt, null);
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([remoteRefund()]);
    expect(app(ReconcilePaymentAction::class)->execute($this->attempt->id)->refundableAmountMinor)->toBe(0);
    expect($refund->refresh()->stripe_refund_id)->toBeNull()->and(PaymentOperation::findOrFail($refund->payment_operation_id)->status)->toBe('unknown');
});

it('rejects two remote refund IDs claiming one operation key atomically', function (): void {
    $refund = reconciliationRefund($this->attempt, null);
    $key = PaymentOperation::findOrFail($refund->payment_operation_id)->idempotency_key;
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([new StripeRefundState('re_first', 'pi_refund', 'ch_refund', 'acct_test', false, 700, 'eur', 'succeeded', 'requested_by_customer', $key), new StripeRefundState('re_second', 'pi_refund', 'ch_refund', 'acct_test', false, 700, 'eur', 'succeeded', 'requested_by_customer', $key)]);
    expect(fn () => app(ReconcilePaymentAction::class)->execute($this->attempt->id))->toThrow(DomainException::class);
    expect($refund->refresh()->stripe_refund_id)->toBeNull()->and(PaymentRefund::count())->toBe(1);
});

it('rejects acceptance when exception money is unconfirmed or changes during verification', function (bool $race): void {
    $this->attempt->update(['state' => 'payment_exception', 'captured_amount_minor' => 0, 'stripe_charge_id' => null]);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $kind) => $kind === 'resolve_payment_exception');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway->shouldReceive('payment')->andReturnUsing(function () use ($race) {
        if ($race) {
            $this->attempt->update(['captured_amount_minor' => 1200, 'refunded_amount_minor' => 700]);

            return refundPaymentState();
        }

        return new StripePaymentState('pi_refund', null, 'acct_test', false, 1200, 0, 0, 'eur', 'processing', 'automatic', 0);
    });
    expect(fn () => app(ResolvePaymentExceptionAction::class)->execute($this->attempt->id, new GenericUser(['id' => 'admin']), (string) Str::uuid()))->toThrow(DomainException::class);
    expect($this->attempt->refresh()->state)->toBe('payment_exception')->and(PaymentOperation::count())->toBe(0);
})->with([true, false]);
