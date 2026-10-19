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
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

it('reconciles a bounded owner worklist only after fresh named system admission', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), fn () => PaymentAttempt::query()->create([
        'order_reference' => 'repair-order', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'attached', 'state' => 'open', 'stripe_payment_intent_id' => 'pi_repair',
    ]));
    $gateway = FakePaymentGateway::fake(app())->willReturn('payment', new StripePaymentState('pi_repair', null, 'acct_native', false, 1000, 0, 0, 'eur', 'processing', 'automatic'));
    expect(fn () => Artisan::call('nvl:payments:reconcile'))->toThrow(OwnershipViolation::class);
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
