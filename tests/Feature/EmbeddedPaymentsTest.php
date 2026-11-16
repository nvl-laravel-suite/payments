<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\PaymentLedgerProjector;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Exceptions\ProviderUnavailable;
use Nvl\Payments\Ledger\PaymentExceptionRaised;
use Nvl\Payments\Ledger\PaymentLedgerFact;
use Nvl\Payments\Ledger\PaymentSucceeded;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\EmbeddedPayments;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Services\PaymentLedgerDelivery;
use Nvl\Payments\Services\PaymentReadService;
use Nvl\Payments\Services\PaymentsAvailability;
use Nvl\Payments\Services\PaymentStateSyncer;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Testing\PaymentLedgerContractKit;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

uses(PaymentsSchemaTestCase::class);

/** @return array<string,mixed> */
function embeddedStripeFacts(string $attempt, array $overrides = []): array
{
    return array_replace([
        'paymentIntentId' => 'pi_embedded', 'chargeId' => null, 'accountId' => 'acct_test', 'livemode' => false,
        'amountMinor' => 1200, 'capturedAmountMinor' => 0, 'refundedAmountMinor' => 0, 'currency' => 'eur',
        'status' => 'requires_payment_method', 'captureMethod' => 'automatic_async', 'amountCapturableMinor' => 0,
        'paymentMethodType' => null, 'partialAuthorizationStatus' => null,
        'clientSecret' => 'pi_embedded_secret_test', 'operationKey' => 'payments:embedded:'.$attempt,
    ], $overrides);
}

beforeEach(function (): void {
    config(['nvl-payments.stripe.account_id' => 'acct_test', 'nvl-payments.stripe.publishable_key' => 'pk_test_fixture']);
    $this->actor = new GenericUser(['id' => 'shopper']);
    $this->order = Mockery::mock(PaymentOrderProvider::class);
    $this->order->shouldReceive('resolve')->byDefault()->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1200, 'EUR', 'Order 1', null, true));
    app()->instance(PaymentOrderProvider::class, $this->order);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $ability, $order) => $actor->getAuthIdentifier() === 'shopper' && (str_ends_with($ability, '_embedded') || in_array($ability, ['start_checkout', 'view'], true)) && $order->reference === 'order-1');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway = FakePaymentGateway::fake(app());
    $this->attemptId = (string) Str::uuid();
});

it('reuses the immutable Stripe intent and returns Payment Element credentials', function (): void {
    $this->gateway->willReturn('createIntent', embeddedStripeFacts($this->attemptId))->willReturn('intent', embeddedStripeFacts($this->attemptId));
    $payments = app(EmbeddedPayments::class);
    $first = $payments->start('order-1', $this->attemptId, $this->actor);
    expect($first)->toMatchArray(['intentId' => 'pi_embedded', 'clientSecret' => 'pi_embedded_secret_test', 'publishableKey' => 'pk_test_fixture', 'status' => 'requires_payment_method']);
    expect($payments->start('order-1', $this->attemptId, $this->actor))->toEqual($first);
    expect(PaymentAttempt::sole())->state->toBe('open')
        ->and(PaymentOperation::sole()->idempotency_key)->toBe('payments:embedded:'.$this->attemptId);
    $this->gateway->assertCalled('createIntent', times: 1);
    expect(fn () => $payments->start('order-1', (string) Str::uuid(), $this->actor))->toThrow(PaymentsException::class);
});

it('rejects below-minimum and unsupported currencies before touching Stripe', function (int $amount, string $currency): void {
    $this->order->shouldReceive('resolve')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', $amount, $currency, 'Order 1', null, true));
    expect(fn () => app(EmbeddedPayments::class)->start('order-1', $this->attemptId, $this->actor))->toThrow(PaymentsException::class);
    expect(PaymentAttempt::count())->toBe(0);
})->with([[49, 'EUR'], [1200, 'USD']]);

it('uses the same creation key after a timeout', function (): void {
    $this->gateway->willThrow('createIntent', new ProviderUnavailable)->willReturn('createIntent', embeddedStripeFacts($this->attemptId));
    $payments = app(EmbeddedPayments::class);
    expect(fn () => $payments->start('order-1', $this->attemptId, $this->actor))->toThrow(ProviderUnavailable::class);
    expect(PaymentOperation::sole()->status)->toBe('unknown');
    expect($payments->start('order-1', $this->attemptId, $this->actor)['intentId'])->toBe('pi_embedded');
    $this->gateway->assertCalled('createIntent', times: 2);
});

it('closes only on confirmed cancel after a provider outage and a processing observation', function (): void {
    $facts = embeddedStripeFacts($this->attemptId);
    $this->gateway->willReturn('createIntent', $facts)
        ->willThrow('cancel', new ProviderUnavailable)
        ->willReturn('cancel', array_replace($facts, ['status' => 'processing']))
        ->willReturn('cancel', array_replace($facts, ['status' => 'canceled']));
    $payments = app(EmbeddedPayments::class);
    $payments->start('order-1', $this->attemptId, $this->actor);
    expect(fn () => $payments->close($this->attemptId, $this->actor))->toThrow(ProviderUnavailable::class);
    expect(PaymentAttempt::sole()->state)->toBe('open');
    expect($payments->close($this->attemptId, $this->actor))->toBeFalse()
        ->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull();
    expect($payments->close($this->attemptId, $this->actor))->toBeTrue()
        ->and(PaymentAttempt::sole()->reservation_key)->toBeNull();
});

it('quarantines distinct succeeded mismatch outcomes without recording captured money', function (array $changes, string $reason): void {
    $facts = embeddedStripeFacts($this->attemptId);
    $this->gateway->willReturn('createIntent', $facts);
    $payments = app(EmbeddedPayments::class);
    $payments->start('order-1', $this->attemptId, $this->actor);
    if ($reason === 'paid_after_close') {
        PaymentAttempt::sole()->update(['state' => 'canceled', 'reservation_key' => null]);
    }
    $succeeded = array_replace($facts, ['status' => 'succeeded', 'capturedAmountMinor' => 1200, 'chargeId' => 'ch_embedded'], $changes);
    $this->gateway->willReturn('intent', $succeeded);
    expect($payments->start('order-1', $this->attemptId, $this->actor)['status'])->toBe('payment_exception');
    expect(PaymentAttempt::sole())->state->toBe('payment_exception')->exception_reason->toBe($reason)->captured_amount_minor->toBe(0);
    $row = PaymentLedgerDelivery::query()->sole();
    app()->instance(PaymentLedgerProjector::class, $projector = Mockery::mock(PaymentLedgerProjector::class));
    $projector->shouldReceive('project')->once()->with(Mockery::on(fn ($fact) => $fact instanceof PaymentExceptionRaised && $fact->reason === $reason));
    expect(app(PaymentLedgerDelivery::class)->deliver($row->id))->toBeTrue();
})->with([[['amountMinor' => 1201], 'amount_mismatch'], [['capturedAmountMinor' => 1199], 'amount_mismatch'], [['currency' => 'usd'], 'currency_mismatch'], [[], 'paid_after_close']]);

it('reuses the customer for a signed-in host reference without rewriting it', function (): void {
    config(['nvl-payments.customers.enabled' => true]);
    $this->gateway->willReturn('createCustomer', 'cus_shopper')->willReturn('createIntent', embeddedStripeFacts($this->attemptId))
        ->willReturn('intent', embeddedStripeFacts($this->attemptId));
    $payments = app(EmbeddedPayments::class);
    $payments->start('order-1', $this->attemptId, $this->actor, 'shopper');
    $payments->start('order-1', $this->attemptId, $this->actor, 'shopper');
    expect(DB::table(PaymentsTables::get(PaymentsTables::Customers))->sole()->stripe_customer_id)->toBe('cus_shopper');
    $this->gateway->assertCalled('createCustomer', times: 1);
    $this->gateway->assertCalled('createIntent', fn ($call) => $call->arguments['customerId'] === 'cus_shopper', times: 1);
});

it('publishes typed success only after outer commit, rolls back failed transactions, and retries the same ID', function (): void {
    $facts = embeddedStripeFacts($this->attemptId);
    $this->gateway->willReturn('createIntent', $facts);
    app(EmbeddedPayments::class)->start('order-1', $this->attemptId, $this->actor);
    $failure = new RuntimeException('host ledger down');
    $projector = new class($failure) implements PaymentLedgerProjector
    {
        public bool $failing = true;

        /** @var list<PaymentLedgerFact> */
        public array $calls = [];

        public function __construct(private readonly Throwable $failure) {}

        public function project(PaymentLedgerFact $fact): void
        {
            expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0);
            if ($this->failing) {
                throw $this->failure;
            }
            $this->calls[] = $fact;
        }
    };
    app()->instance(PaymentLedgerProjector::class, $projector);
    $success = array_replace($facts, ['status' => 'succeeded', 'capturedAmountMinor' => 1200, 'chargeId' => 'ch_embedded', 'cardBrand' => 'visa', 'cardLastFour' => '4242', 'wallet' => 'apple_pay', 'providerOccurredAt' => 1820000000]);
    $connection = (new PaymentAttempt)->getConnection();
    $connection->beginTransaction();
    app(PaymentStateSyncer::class)->sync($success);
    expect($projector->calls)->toBe([]);
    $connection->rollBack();
    expect(PaymentLedgerDelivery::query()->count())->toBe(0);
    $connection->transaction(fn () => app(PaymentStateSyncer::class)->sync($success));
    $row = PaymentLedgerDelivery::query()->sole();
    expect($row->delivered_at)->toBeNull();
    $kit = new PaymentLedgerContractKit;
    $kit->assertFailureIsRetryable(app(PaymentLedgerDelivery::class), $row->id, $failure, function () use ($projector): void {
        $projector->failing = false;
    });
    expect($projector->calls[0])->toBeInstanceOf(PaymentSucceeded::class)->cardLastFour->toBe('4242')->amountMinor->toBe(1200);
    $kit->assertReplay(app(PaymentLedgerDelivery::class), $row->id, fn () => array_map(fn ($fact) => $fact->id(), $projector->calls));
});

it('checks live key mode and blocks Stripe-bound operations inside the Payments transaction', function (): void {
    config(['nvl-payments.stripe.secret' => 'rk_live_fixture', 'nvl-payments.stripe.livemode' => true]);
    expect(app(PaymentsAvailability::class)->acceptsPayments())->toBeFalse();
    config(['nvl-payments.allow_live' => true]);
    expect(app(PaymentsAvailability::class)->acceptsPayments())->toBeTrue();
    (new PaymentAttempt)->getConnection()->transaction(function (): void {
        expect(fn () => app(EmbeddedPayments::class)->start('order-1', $this->attemptId, $this->actor))->toThrow(DomainException::class, 'payments connection');
    });
});

it('cancels an expired intent during settlement and releases only its confirmed reservation', function (): void {
    $facts = embeddedStripeFacts($this->attemptId);
    $this->gateway->willReturn('createIntent', $facts)->willReturn('cancel', array_replace($facts, ['status' => 'canceled']));
    $payments = app(EmbeddedPayments::class);
    $payments->start('order-1', $this->attemptId, $this->actor);
    PaymentAttempt::sole()->update(['expires_at' => now()->subMinute()]);
    expect($payments->settle($this->attemptId, $this->actor))->state->toBe('canceled')->reservation_key->toBeNull();
});

it('does not repeat an unknown creation after its safe retry window', function (): void {
    $this->gateway->willThrow('createIntent', new ProviderUnavailable);
    $payments = app(EmbeddedPayments::class);
    expect(fn () => $payments->start('order-1', $this->attemptId, $this->actor))->toThrow(ProviderUnavailable::class);
    PaymentAttempt::sole()->update(['expires_at' => now()->subMinute()]);
    expect(fn () => $payments->start('order-1', $this->attemptId, $this->actor))->toThrow(PaymentsException::class, 'cannot create another intent');
    $this->gateway->assertCalled('createIntent', times: 1);
    expect(PaymentAttempt::sole()->reservation_key)->not->toBeNull();
});

it('reports a clear configuration error before reservation when the publishable key is absent', function (): void {
    config(['nvl-payments.stripe.publishable_key' => null]);
    expect(fn () => app(EmbeddedPayments::class)->start('order-1', $this->attemptId, $this->actor))
        ->toThrow(PaymentsException::class, 'Configure a publishable Stripe key');
    expect(PaymentAttempt::count())->toBe(0);
    $this->gateway->assertCalled('createIntent', times: 0);
});

it('keeps an existing embedded reservation when the host starts hosted Checkout', function (): void {
    config(['nvl-payments.checkout.return_hosts' => ['shop.test']]);
    $this->gateway->willReturn('createIntent', embeddedStripeFacts($this->attemptId));
    app(EmbeddedPayments::class)->start('order-1', $this->attemptId, $this->actor);

    expect(fn () => app(PaymentCheckout::class)->start('order-1', $this->actor, 'https://shop.test/success', 'https://shop.test/cancel'))
        ->toThrow(PaymentsException::class, 'An embedded payment already reserves the order.');
    $this->gateway->assertCalled('createCheckout', times: 0);
});

it('settles a successful automatic payment and exposes the expanded card facts', function (string $capture): void {
    config(['nvl-payments.embedded.capture_method' => $capture]);
    $facts = embeddedStripeFacts($this->attemptId, ['captureMethod' => $capture]);
    $this->gateway->willReturn('createIntent', $facts)
        ->willReturn('payment', array_replace($facts, [
            'status' => 'succeeded', 'capturedAmountMinor' => 1200, 'chargeId' => 'ch_embedded',
            'cardBrand' => 'visa', 'cardLastFour' => '4242', 'cardExpiryMonth' => 5, 'cardExpiryYear' => 2032, 'wallet' => 'apple_pay',
        ]))->willReturn('refunds', []);
    $payments = app(EmbeddedPayments::class);
    $payments->start('order-1', $this->attemptId, $this->actor);

    expect($payments->settle($this->attemptId, $this->actor))
        ->state->toBe('captured')
        ->captured_amount_minor->toBe(1200);
    expect(app(PaymentReadService::class)->forOrder('order-1', $this->actor)['payments'][0])->toMatchArray([
        'status' => 'captured',
        'chargeId' => 'ch_embedded',
        'cardBrand' => 'visa',
        'cardLastFour' => '4242',
        'cardExpiryMonth' => 5,
        'cardExpiryYear' => 2032,
        'wallet' => 'apple_pay',
    ]);
    $this->gateway->assertCalled('capture', times: 0);
})->with(['automatic', 'automatic_async']);
