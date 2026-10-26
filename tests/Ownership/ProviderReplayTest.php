<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentWebhookEvent;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\OwnerScopeTestCase;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

it('routes signed payments through immutable persisted correlation and replays only inside that owner', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), fn () => PaymentAttempt::query()->create([
        'order_reference' => 'native-order', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'open',
        'stripe_payment_intent_id' => 'pi_native', 'stripe_account_id' => 'acct_native', 'stripe_livemode' => false, 'capture_method' => 'automatic',
    ]));
    $gateway = FakePaymentGateway::fake(app())->willReturn('payment', ['paymentIntentId' => 'pi_native', 'chargeId' => 'ch_native', 'accountId' => 'acct_native', 'livemode' => false, 'amountMinor' => 1000, 'capturedAmountMinor' => 1000, 'refundedAmountMinor' => 0, 'currency' => 'eur', 'status' => 'succeeded', 'captureMethod' => 'automatic', 'amountCapturableMinor' => null, 'paymentMethodType' => null, 'partialAuthorizationStatus' => null]);
    $body = json_encode(['id' => 'evt_native', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'livemode' => false,
        'data' => ['object' => ['id' => 'pi_native', 'object' => 'payment_intent', 'metadata' => ['owner_id' => 8]]]], JSON_THROW_ON_ERROR);
    $stamp = time();
    $signature = hash_hmac('sha256', $stamp.'.'.$body, 'whsec_payments');
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$stamp.',v1='.$signature];
    $this->call('POST', '/nvl/payments/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);
    $this->withoutExceptionHandling();
    expect(fn () => $this->call('POST', '/nvl/payments/stripe/webhook', [], [], [], $headers, $body))->toThrow(OwnershipViolation::class);
    $gateway->assertCalled('payment', times: 0);
    config(['nvl-ownership.system_operations' => ['payments.provider-callback']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            if ($operation !== 'payments.provider-callback' || $owner->getKey() !== 7) {
                throw new OwnershipViolation;
            }
        }
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function () use ($headers, $body): void {
        $this->call('POST', '/nvl/payments/stripe/webhook', [], [], [], $headers, $body)->assertOk();
        expect(PaymentAttempt::query()->count())->toBe(0)->and(app(OwnershipContext::class)->requireOwner()->key)->toBe('8');
    });
    $this->call('POST', '/nvl/payments/stripe/webhook', [], [], [], $headers, $body)->assertOk();
    $gateway->assertCalled('payment', times: 1);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        expect(PaymentAttempt::query()->sole()->state)->toBe('captured')->and(PaymentWebhookEvent::query()->count())->toBe(1);
    });
});

it('preserves canonical boolean provider facts across native database serialization', function (bool $mode): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($mode): void {
        $receipt = PaymentWebhookEvent::query()->create(['stripe_event_id' => 'evt_bool_'.(int) $mode, 'type' => 'payment_intent.succeeded',
            'stripe_object_id' => 'pi_bool', 'stripe_livemode' => $mode, 'status' => 'processed']);
        expect($receipt->fresh()->stripe_livemode)->toBe($mode);
        expect(fn () => $receipt->getConnection()->table($receipt->getTable())->where('id', $receipt->id)->update(['stripe_livemode' => ! $mode]))
            ->toThrow(QueryException::class);
    });
})->with([false, true]);
