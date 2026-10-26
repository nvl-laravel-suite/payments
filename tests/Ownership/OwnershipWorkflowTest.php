<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\Fixtures\StartCheckoutAction;
use Nvl\Payments\Tests\OwnerScopeTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

beforeEach(function (): void {
    app()->bind(PaymentOrderProvider::class, fn () => new class implements PaymentOrderProvider
    {
        public function resolve(string $orderReference): OrderPaymentSnapshot
        {
            return new OrderPaymentSnapshot($orderReference, 'v1', 1000, 'EUR', 'Native order', 'payer@example.test', true, owner: app(OwnershipContext::class)->requireOwner());
        }
    });
    app()->bind(PaymentManagementAccess::class, fn () => new class implements PaymentManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void {}
    });
});

it('keeps equal host order references and their reservation keys separate across native owners', function (): void {
    $gateway = FakePaymentGateway::fake(app());
    foreach ([7, 8] as $key) {
        $gateway->willReturn('createCheckout', ['sessionId' => 'cs_native_'.$key, 'url' => 'https://example.test/checkout/'.$key, 'expiresAt' => CarbonImmutable::now()->addHour()]);
    }
    $attempts = [];
    foreach ([7, 8] as $key) {
        $attempts[$key] = app(OwnershipRunner::class)->run(Company::query()->findOrFail($key), Actor::query()->findOrFail(1), function () use ($key) {
            expect(app(StartCheckoutAction::class)->execute('same-order', Actor::query()->findOrFail(1), 'https://example.test/success', 'https://example.test/cancel')['sessionId'])->toBe('cs_native_'.$key);

            return PaymentAttempt::query()->sole();
        });
    }
    expect($attempts[7]->id)->not->toBe($attempts[8]->id)->and($attempts[7]->reservation_key)->not->toBe($attempts[8]->reservation_key);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function () use ($attempts): void {
        expect(PaymentAttempt::query()->whereKey($attempts[7]->id)->exists())->toBeFalse()
            ->and(fn () => $attempts[7]->refresh())->toThrow(OwnershipViolation::class);
    });
    $gateway->assertCalled('createCheckout', times: 2);
    expect(fn () => PaymentAttempt::query()->get())->toThrow(OwnershipViolation::class);
});
