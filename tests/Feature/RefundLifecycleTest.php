<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Payments\Actions\ReconcilePaymentAction;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;
use Stripe\Exception\InvalidRequestException;

require_once __DIR__.'/../RefundTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    setupRefundHost();
    $this->attempt = createRefundAttempt();
    $this->gateway = Mockery::mock(PaymentGateway::class);
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->uuid = (string) Str::uuid();
});

it('persists full and partial refund outcomes and replays the same UUID without another remote call', function (int $amount, string $status): void {
    $this->gateway->shouldReceive('payment')->once()->andReturnUsing(function () {
        expect(DB::connection()->transactionLevel())->toBe(0);

        return refundPaymentState();
    });
    $this->gateway->shouldReceive('refunds')->once()->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->with('pi_refund', $amount, 'requested_by_customer', 'payments:refund:'.$this->uuid)->andReturnUsing(function () use ($amount, $status): StripeRefundState {
        expect(PaymentRefund::sole()->status)->toBe('reserved')->and(PaymentOperation::sole()->status)->toBe('reserved')->and(DB::connection()->transactionLevel())->toBe(0);

        return remoteRefund(amount: $amount, status: $status);
    });
    $result = refundPayment($amount, $this->uuid, note: 'Internal only');
    expect($result->status)->toBe($status)->and($result->amountMinor)->toBe($amount)->and($result->stripeRefundId)->toBe('re_test')
        ->and(refundPayment($amount, $this->uuid, note: 'Internal only'))->toEqual($result)
        ->and(PaymentRefund::sole()->internal_note)->toBe('Internal only')->and(PaymentOperation::sole()->status)->toBe('completed');
})->with([700, 1200])->with(['pending', 'requires_action', 'succeeded', 'failed', 'canceled']);

it('supports repeated partial refunds without double counting known remote refunds', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(), refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([], [remoteRefund()]);
    $this->gateway->shouldReceive('refund')->with('pi_refund', 700, 'requested_by_customer', Mockery::type('string'))->once()->andReturn(remoteRefund());
    $this->gateway->shouldReceive('refund')->with('pi_refund', 500, 'requested_by_customer', Mockery::type('string'))->once()->andReturn(remoteRefund('re_second', 500));
    refundPayment();
    expect(refundPayment(500)->status)->toBe('succeeded')->and(PaymentRefund::sum('amount_minor'))->toBe(1200);
    expect(fn () => refundPayment(1))->toThrow(DomainException::class);
});

it('reserves Dashboard refunds including pending ones without counting the Stripe aggregate twice', function (string $status): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturn([remoteRefund('re_dashboard', 700, $status)]);
    expect(fn () => refundPayment(501))->toThrow(DomainException::class);
    $this->gateway->shouldReceive('refund')->once()->andReturn(remoteRefund('re_local', 500));
    expect(refundPayment(500)->status)->toBe('succeeded')->and(PaymentRefund::count())->toBe(1);
})->with(['succeeded', 'pending', 'requires_action']);

it('keeps ambiguous reservations blocked and prevents overdrawing with another UUID', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andThrow(new RuntimeException('timeout'));
    expect(fn () => refundPayment(700, $this->uuid))->toThrow(RuntimeException::class, 'timeout');
    expect(PaymentOperation::sole()->status)->toBe('unknown')->and(PaymentRefund::sole()->status)->toBe('reserved');
    expect(fn () => refundPayment(700, $this->uuid))->toThrow(DomainException::class);
    expect(fn () => refundPayment(501))->toThrow(DomainException::class);
    expect(PaymentRefund::count())->toBe(1);
});

it('releases a pending reservation only after confirmed remote failure', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([], [remoteRefund(status: 'pending')], [remoteRefund(status: 'failed')]);
    $this->gateway->shouldReceive('refund')->andReturn(remoteRefund(status: 'pending'), remoteRefund('re_retry', 1200));
    refundPayment();
    expect(fn () => refundPayment(501))->toThrow(DomainException::class);
    expect(refundPayment(1200)->status)->toBe('succeeded')->and(PaymentRefund::where('stripe_refund_id', 're_test')->sole()->status)->toBe('failed');
});

it('rejects changed immutable UUID inputs', function (int $amount, string $reason, ?string $note): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->once()->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andReturn(remoteRefund());
    refundPayment(700, $this->uuid);
    expect(fn () => refundPayment($amount, $this->uuid, $reason, $note))->toThrow(DomainException::class);
})->with([[701, 'requested_by_customer', null], [700, 'duplicate', null], [700, 'requested_by_customer', 'changed']]);

it('rejects nonpositive amounts and unsupported reasons before remote access', function (int $amount, string $reason): void {
    expect(fn () => refundPayment($amount, reason: $reason))->toThrow(InvalidArgumentException::class);
    expect(PaymentOperation::count())->toBe(0);
})->with([[0, 'duplicate'], [-1, 'duplicate'], [1201, 'duplicate'], [700, 'arbitrary']]);

it('retains default deny authorization', function (): void {
    app()->instance(PaymentManagementAccess::class, new DenyPaymentManagementAccess);
    expect(fn () => refundPayment())->toThrow(AuthorizationException::class);
    expect(PaymentOperation::count())->toBe(0);
});

it('rejects authorization-only and mismatched remote payments', function (int $captured): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(captured: $captured));
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    expect(fn () => refundPayment())->toThrow(DomainException::class);
    expect(PaymentOperation::count())->toBe(0);
})->with([0, 500]);

it('marks confirmed Stripe balance rejections failed and refreshes the external refund facts', function (): void {
    $this->gateway->shouldReceive('payment')->twice()->andReturn(refundPaymentState(), refundPaymentState(1200));
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andThrow(InvalidRequestException::factory('Already refunded', 400, null, null, null, 'charge_already_refunded'));
    expect(fn () => refundPayment(700, $this->uuid))->toThrow(InvalidRequestException::class);
    expect(PaymentRefund::sole()->status)->toBe('failed')->and(PaymentOperation::sole()->status)->toBe('failed')
        ->and($this->attempt->refresh()->refunded_amount_minor)->toBe(1200);
    expect(refundPayment(700, $this->uuid)->status)->toBe('failed');
});

it('keeps malformed refund responses unresolved', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andReturn(remoteRefund(amount: 600));
    expect(fn () => refundPayment())->toThrow(DomainException::class);
    expect(PaymentRefund::sole()->status)->toBe('reserved')->and(PaymentOperation::sole()->status)->toBe('unknown');
});

it('rejects an enclosing transaction before remote access', function (): void {
    DB::beginTransaction();
    try {
        expect(fn () => refundPayment())->toThrow(DomainException::class);
        expect(PaymentOperation::count())->toBe(0);
    } finally {
        DB::rollBack();
    }
});

it('honors newer locally synchronized refunded facts when a preflight read completes late', function (): void {
    $this->gateway->shouldReceive('payment')->andReturnUsing(function () {
        $this->attempt->update(['state' => 'refunded', 'refunded_amount_minor' => 1200]);

        return refundPaymentState();
    });
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    expect(fn () => refundPayment())->toThrow(DomainException::class);
    expect(PaymentRefund::count())->toBe(0);
});

it('does not release reservations on an unclassified Stripe rejection', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andThrow(InvalidRequestException::factory('Indeterminate error', 400, null, null, null, 'idempotency_key_in_use'));
    expect(fn () => refundPayment())->toThrow(InvalidRequestException::class);
    expect(PaymentRefund::sole()->status)->toBe('reserved')->and(PaymentOperation::sole()->status)->toBe('unknown');
    expect(fn () => refundPayment(501))->toThrow(DomainException::class);
});

it('rejects malformed refund identity and financial facts without releasing the reservation', function (string $field, mixed $value): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    $facts = get_object_vars(remoteRefund());
    $facts[$field] = $value;
    $this->gateway->shouldReceive('refund')->once()->andReturn(new StripeRefundState(...$facts));
    expect(fn () => refundPayment())->toThrow(DomainException::class);
    expect(PaymentRefund::sole()->status)->toBe('reserved')->and(PaymentOperation::sole()->status)->toBe('unknown');
})->with([['refundId', 'pi_wrong'], ['paymentIntentId', 'pi_wrong'], ['chargeId', 'ch_wrong'], ['accountId', 'acct_other'], ['livemode', true], ['currency', 'usd'], ['reason', 'fraudulent'], ['status', 'mystery']]);

it('validates remote Dashboard refund identities before admitting a refund', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([new StripeRefundState('re_other', 'pi_other', 'ch_other', 'acct_test', false, 700, 'eur', 'pending', 'requested_by_customer')]);
    expect(fn () => refundPayment())->toThrow(DomainException::class);
    expect(PaymentOperation::count())->toBe(0);
});

it('passes only the stored original payment and Stripe reason to the gateway', function (string $reason): void {
    $this->attempt->update(['stripe_payment_intent_id' => null]);
    $this->gateway->shouldReceive('payment')->with('ch_refund')->once()->andReturn(new StripePaymentState(null, 'ch_refund', 'acct_test', false, 1200, 1200, 0, 'eur', 'succeeded', 'automatic'));
    $this->gateway->shouldReceive('refunds')->with('ch_refund')->once()->andReturn([]);
    $this->gateway->shouldReceive('refund')->with('ch_refund', 700, $reason, 'payments:refund:'.$this->uuid)->once()->andReturn(new StripeRefundState('re_original', null, 'ch_refund', 'acct_test', false, 700, 'eur', 'succeeded', $reason));
    expect(refundPayment(700, $this->uuid, $reason, 'destination=untrusted note')->stripeRefundId)->toBe('re_original');
})->with(['duplicate', 'fraudulent']);

it('rejects disabled operation and absent payment identity before gateway calls', function (bool $enabled): void {
    config(['payments.enabled' => $enabled]);
    if ($enabled) {
        $this->attempt->update(['stripe_payment_intent_id' => null, 'stripe_charge_id' => null]);
    }
    expect(fn () => refundPayment())->toThrow(DomainException::class);
    expect(PaymentOperation::count())->toBe(0);
})->with([true, false]);

it('preserves a reconciled terminal refund when a pending creation response arrives late', function (string $status): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andReturnUsing(function () use ($status): StripeRefundState {
        PaymentRefund::sole()->update(['stripe_refund_id' => 're_test', 'status' => $status, 'last_synced_at' => now()]);

        return remoteRefund(status: 'pending');
    });
    expect(refundPayment()->status)->toBe($status)->and(PaymentRefund::sole()->status)->toBe($status);
})->with(['succeeded', 'failed', 'canceled']);

it('reconciles succeeded refunds through requires action and releases only confirmed returned funds', function (string $finalStatus): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(), refundPaymentState(700), refundPaymentState(700), refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn(
        [],
        [remoteRefund(status: 'requires_action')],
        [remoteRefund(status: 'requires_action'), remoteRefund('re_second', 100, 'pending')],
        [remoteRefund(status: $finalStatus), remoteRefund('re_second', 100, 'pending')],
    );
    $this->gateway->shouldReceive('refund')->with('pi_refund', 700, 'requested_by_customer', Mockery::type('string'))->once()->andReturn(remoteRefund());
    $this->gateway->shouldReceive('refund')->with('pi_refund', 100, 'requested_by_customer', Mockery::type('string'))->once()->andReturn(remoteRefund('re_second', 100, 'pending'));
    $this->gateway->shouldReceive('refund')->with('pi_refund', 1100, 'requested_by_customer', Mockery::type('string'))->once()->andReturn(remoteRefund('re_after_return', 1100));

    refundPayment();
    refundPayment(100);
    expect(PaymentRefund::where('stripe_refund_id', 're_test')->sole()->status)->toBe('requires_action');
    expect(fn () => refundPayment(401))->toThrow(DomainException::class);
    expect(refundPayment(1100)->status)->toBe('succeeded')
        ->and(PaymentRefund::where('stripe_refund_id', 're_test')->sole()->status)->toBe($finalStatus);
})->with(['failed', 'canceled']);

it('keeps a newer requires action observation when the original succeeded creation response arrives late', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andReturnUsing(function (): StripeRefundState {
        PaymentRefund::sole()->update(['stripe_refund_id' => 're_test', 'status' => 'requires_action', 'last_synced_at' => now()]);

        return remoteRefund(status: 'succeeded');
    });
    expect(refundPayment()->status)->toBe('requires_action');
});

it('accepts non-card Stripe refund IDs from creation responses', function (): void {
    $this->gateway->shouldReceive('payment')->once()->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->once()->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andReturn(remoteRefund('pyr_noncard', 700, 'requires_action'));
    $result = refundPayment(700, $this->uuid);
    expect($result->stripeRefundId)->toBe('pyr_noncard')->and($result->status)->toBe('requires_action')
        ->and(refundPayment(700, $this->uuid))->toEqual($result);
});

it('reserves non-card Dashboard refund amounts using their provider identity', function (): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([remoteRefund('pyr_dashboard', 700, 'requires_action')]);
    expect(fn () => refundPayment(501))->toThrow(DomainException::class);
    $this->gateway->shouldReceive('refund')->once()->andReturn(remoteRefund('pyr_remainder', 500));
    expect(refundPayment(500)->stripeRefundId)->toBe('pyr_remainder');
});

it('allows an explicitly authorized refund of confirmed exception money while retaining its block', function (): void {
    $this->attempt->update(['state' => 'payment_exception', 'reservation_key' => 'held']);
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState());
    $this->gateway->shouldReceive('refunds')->andReturn([]);
    $this->gateway->shouldReceive('refund')->once()->andReturn(remoteRefund());
    expect(refundPayment()->status)->toBe('succeeded')->and($this->attempt->refresh()->state)->toBe('payment_exception')
        ->and($this->attempt->reservation_key)->toBe('held')->and(PaymentOperation::sole()->actor_reference)->toBe('admin');
});

it('recovers the original reservation when reconciliation precedes the refund create response', function (bool $lost): void {
    $this->gateway->shouldReceive('payment')->andReturn(refundPaymentState(), refundPaymentState(700));
    $this->gateway->shouldReceive('refunds')->andReturnUsing(function (): array {
        $operation = PaymentOperation::first();

        return $operation === null ? [] : [new StripeRefundState('re_test', 'pi_refund', 'ch_refund', 'acct_test', false, 700, 'eur', 'succeeded', 'requested_by_customer', $operation->idempotency_key)];
    });
    $this->gateway->shouldReceive('refund')->once()->andReturnUsing(function () use ($lost): StripeRefundState {
        app(ReconcilePaymentAction::class)->execute($this->attempt->id);
        if ($lost) {
            throw new RuntimeException('response lost');
        }

        return remoteRefund(status: 'pending');
    });
    if ($lost) {
        expect(fn () => refundPayment(700, $this->uuid))->toThrow(RuntimeException::class);
    } else {
        expect(refundPayment(700, $this->uuid)->status)->toBe('succeeded');
    }
    expect(refundPayment(700, $this->uuid)->status)->toBe('succeeded')->and(PaymentRefund::count())->toBe(1)
        ->and(PaymentOperation::sole()->status)->toBe('completed');
    expect(app(ReconcilePaymentAction::class)->execute($this->attempt->id)->refundableAmountMinor)->toBe(500);
})->with([true, false]);
