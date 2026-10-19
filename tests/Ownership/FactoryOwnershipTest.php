<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Tests\OwnerScopeTestCase;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

it('builds inherited payment factory graphs in the current native owner and rejects held foreign parents', function (): void {
    $operation = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () {
        $operation = PaymentOperation::factory()->create();
        $refund = PaymentRefund::factory()->forOperation($operation)->create();
        expect($refund->getAttribute('owner_scope_key'))->toBe($operation->getAttribute('owner_scope_key'));

        return $operation;
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function () use ($operation): void {
        expect(fn () => PaymentRefund::factory()->forOperation($operation))->toThrow(OwnershipViolation::class);
    });
});
