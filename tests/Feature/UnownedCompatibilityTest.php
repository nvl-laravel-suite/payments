<?php

declare(strict_types=1);

use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Tenancy\Providers\TenancyServiceProvider;

uses(PaymentsSchemaTestCase::class);

it('keeps unowned payments usable when another installed package uses the legacy tenancy runtime', function (): void {
    if (! class_exists(TenancyServiceProvider::class)) {
        $this->markTestSkipped('Optional legacy tenancy runtime is not installed.');
    }
    config(['nvl-tenancy.enabled' => true, 'nvl-tenancy.migrations.enabled' => false]);
    app()->register(TenancyServiceProvider::class);
    $attempt = PaymentAttempt::factory()->create();
    expect($attempt->fresh()->id)->toBe($attempt->id)->and(PaymentAttempt::query()->count())->toBe(1);
});
