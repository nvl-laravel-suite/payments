<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\OwnerScopeTestCase;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

it('reconciles a bounded owner worklist only after fresh named system admission', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), fn () => PaymentAttempt::query()->create([
        'order_reference' => 'repair-order', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'attached', 'state' => 'open', 'stripe_payment_intent_id' => 'pi_repair',
    ]));
    $gateway = FakePaymentGateway::fake(app())->willReturn('payment', ['paymentIntentId' => 'pi_repair', 'chargeId' => null, 'accountId' => 'acct_native', 'livemode' => false, 'amountMinor' => 1000, 'capturedAmountMinor' => 0, 'refundedAmountMinor' => 0, 'currency' => 'eur', 'status' => 'processing', 'captureMethod' => 'automatic', 'amountCapturableMinor' => null, 'paymentMethodType' => null, 'partialAuthorizationStatus' => null]);
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(1)->and(Artisan::output())->toContain('0 reconciled; 1 failed.');
    $gateway->assertCalled('payment', times: 0);
    config(['nvl-ownership.system_operations' => ['payments.reconcile']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            if ($operation !== 'payments.reconcile' || $owner->getKey() !== 7) {
                throw new OwnershipViolation;
            }
        }
    });
    expect(Artisan::call('nvl:payments:reconcile'))->toBe(0)->and(app(OwnershipContext::class)->snapshot()->owner)->toBeNull();
    $gateway->assertCalled('payment', times: 1);
});

it('keeps reconciling later owners when one owner is refused system admission', function (): void {
    foreach ([7 => 'pi_suspended', 8 => 'pi_active'] as $company => $intent) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($company), Actor::query()->findOrFail(1), fn () => PaymentAttempt::query()->create([
            'order_reference' => 'order-'.$company, 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'attached', 'state' => 'open', 'stripe_payment_intent_id' => $intent,
            // The refused owner's attempt is never stamped, so it stays first in every later sweep.
            'last_reconcile_attempt_at' => $company === 7 ? null : now()->subDay(),
        ]));
    }
    Company::query()->whereKey(7)->update(['active' => false]);
    config(['nvl-ownership.system_operations' => ['payments.reconcile']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void {}
    });
    $gateway = FakePaymentGateway::fake(app())->willReturn('payment', ['paymentIntentId' => 'pi_active', 'chargeId' => null, 'accountId' => 'acct_native', 'livemode' => false, 'amountMinor' => 1000, 'capturedAmountMinor' => 0, 'refundedAmountMinor' => 0, 'currency' => 'eur', 'status' => 'processing', 'captureMethod' => 'automatic', 'amountCapturableMinor' => null, 'paymentMethodType' => null, 'partialAuthorizationStatus' => null]);

    expect(Artisan::call('nvl:payments:reconcile'))->toBe(1)
        ->and(Artisan::output())->toContain('1 reconciled; 1 failed.')
        ->and(app(OwnershipContext::class)->snapshot()->owner)->toBeNull();
    $gateway->assertCalled('payment', times: 1);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function (): void {
        expect(PaymentAttempt::query()->sole()->state)->toBe('processing');
    });
});
