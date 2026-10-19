<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Payments\Actions\RecoverCheckoutAction;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentWebhookEvent;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\LegacyOwnerScopeTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Support\Ownership\Contracts\OwnershipContext;

uses(LegacyOwnerScopeTestCase::class);

it('recovers a pending pre-cutover operation with its original provider key without creating another checkout', function (): void {
    app()->bind(PaymentOrderProvider::class, fn () => new class implements PaymentOrderProvider
    {
        public function resolve(string $reference): OrderPaymentSnapshot
        {
            return new OrderPaymentSnapshot($reference, 'v1', 1000, 'EUR', 'Legacy order', 'payer@example.test', true, owner: app(OwnershipContext::class)->requireOwner());
        }
    });
    app()->bind(PaymentManagementAccess::class, fn () => new class implements PaymentManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void {}
    });
    $gateway = FakePaymentGateway::fake(app())->willReturn('checkout', new StripeCheckoutState('cs_old', 'pi_old', 'acct_native', false, 1000, 'eur', 'complete', 'paid', 'old-order', 'v1', CarbonImmutable::now()->addHour(), LegacyOwnerScopeTestCase::OperationKey))
        ->willReturn('payment', new StripePaymentState('pi_old', 'ch_old', 'acct_native', false, 1000, 1000, 0, 'eur', 'succeeded', 'automatic'))->willReturn('refunds', []);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        app(RecoverCheckoutAction::class)->execute(LegacyOwnerScopeTestCase::Attempt, 'cs_old', Actor::query()->findOrFail(1));
        $operation = PaymentOperation::query()->sole();
        expect($operation->id)->toBe(LegacyOwnerScopeTestCase::Operation)->and($operation->idempotency_key)->toBe(LegacyOwnerScopeTestCase::OperationKey)
            ->and($operation->input_fingerprint)->toBe(hash('sha256', 'original immutable input'))->and($operation->status)->toBe('completed')
            ->and(PaymentAttempt::query()->sole()->stripe_checkout_session_id)->toBe('cs_old')->and(PaymentAttempt::query()->sole()->state)->toBe('captured');
    });
    $gateway->assertCalled('createCheckout', times: 0);
});

it('acknowledges completed global provider history without assigning it a new owner or calling the provider again', function (): void {
    $gateway = FakePaymentGateway::fake(app());
    $body = json_encode(['id' => 'evt_old', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'livemode' => false,
        'data' => ['object' => ['id' => 'pi_old', 'object' => 'payment_intent']]], JSON_THROW_ON_ERROR);
    $stamp = time();
    $signature = hash_hmac('sha256', $stamp.'.'.$body, 'whsec_payments');
    $this->call('POST', '/nvl/payments/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$stamp.',v1='.$signature], $body)->assertOk();
    $gateway->assertCalled('payment', times: 0);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        expect(PaymentWebhookEvent::query()->count())->toBe(0)->and(PaymentOperation::query()->sole()->status)->toBe('unknown');
    });
});
