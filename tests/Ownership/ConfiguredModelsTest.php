<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Tests\OwnerScopeTestCase;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

class OwnedPaymentAttemptExtension extends PaymentAttempt {}
class OwnedPaymentRefundExtension extends PaymentRefund {}
class ConfiguredOwnedPaymentsTestCase extends OwnerScopeTestCase
{
    protected function setUp(): void
    {
        Relation::morphMap([], false);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Relation::morphMap([], false);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set([
            'nvl-payments.models.attempt' => OwnedPaymentAttemptExtension::class,
            'nvl-payments.models.refund' => OwnedPaymentRefundExtension::class,
        ]);
    }
}
uses(ConfiguredOwnedPaymentsTestCase::class);

it('adopts the exact configured financial roots and retains foreign owner denial during restoration', function (): void {
    $refund = app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), function (): PaymentRefund {
        $operation = PaymentOperation::factory()->create();
        $refund = PaymentRefund::factory()->forOperation($operation)->create();
        expect($refund)->toBeInstanceOf(OwnedPaymentRefundExtension::class)
            ->and($refund->paymentAttempt)->toBeInstanceOf(OwnedPaymentAttemptExtension::class)
            ->and($refund->getAttribute('owner_scope_key'))->toBe($operation->getAttribute('owner_scope_key'))
            ->and($refund->newQueryForRestoration($refund->id)->sole())->toBeInstanceOf(OwnedPaymentRefundExtension::class);
        expect(fn () => PaymentAttempt::query()->count())->toThrow(OwnershipViolation::class);

        return $refund;
    });
    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function () use ($refund): void {
        expect($refund->newQueryForRestoration($refund->id)->exists())->toBeFalse();
    });
});
