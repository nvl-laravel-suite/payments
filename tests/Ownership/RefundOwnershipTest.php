<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Payments\Actions\RefundPaymentAction;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\OwnerScopeTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;
use Nvl\Support\Ownership\Contracts\OwnershipContext;

uses(OwnerScopeTestCase::class);

it('keeps refund and operation references inside the native source payment owner', function (): void {
    app()->bind(PaymentOrderProvider::class, fn () => new class implements PaymentOrderProvider
    {
        public function resolve(string $reference): OrderPaymentSnapshot
        {
            return new OrderPaymentSnapshot($reference, 'v1', 1000, 'EUR', 'Paid order', 'payer@example.test', true, owner: app(OwnershipContext::class)->requireOwner());
        }
    });
    app()->bind(PaymentManagementAccess::class, fn () => new class implements PaymentManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void {}
    });
    FakePaymentGateway::fake(app())->willReturn('payment', new StripePaymentState('pi_paid', 'ch_paid', 'acct_native', false, 1000, 1000, 0, 'eur', 'succeeded', 'automatic'))
        ->willReturn('refunds', [])->willReturn('refund', new StripeRefundState('re_paid', 'pi_paid', 'ch_paid', 'acct_native', false, 200, 'eur', 'succeeded', 'requested_by_customer'));
    $refund = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () {
        $attempt = PaymentAttempt::query()->create(['order_reference' => 'paid-order', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'captured',
            'stripe_payment_intent_id' => 'pi_paid', 'stripe_charge_id' => 'ch_paid', 'stripe_account_id' => 'acct_native', 'stripe_livemode' => false, 'capture_method' => 'automatic', 'captured_amount_minor' => 1000]);
        app(RefundPaymentAction::class)->execute($attempt->id, Actor::query()->findOrFail(1), 200, 'requested_by_customer', null, (string) Str::uuid());
        $refund = PaymentRefund::query()->sole();
        expect($refund->getAttribute('owner_scope_key'))->toBe($attempt->getAttribute('owner_scope_key'))->and($refund->stripe_refund_id)->toBe('re_paid');

        return $refund;
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function () use ($refund): void {
        expect(PaymentRefund::query()->whereKey($refund->id)->exists())->toBeFalse();
    });
});
