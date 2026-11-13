<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Tests\OwnershipTestCase;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Payments\Providers\PaymentsServiceProvider;
use Nvl\Payments\Tests\Fixtures\PaymentsWebhookController;

/** Activates native Payments roots without Tenancy, Auth or a synthetic owner. */
abstract class OwnerScopeTestCase extends OwnershipTestCase
{
    /** Install the consumer endpoint independently of the package provider. */
    protected function defineRoutes($router): void
    {
        $router->post('/nvl/payments/stripe/webhook', PaymentsWebhookController::class)->name('nvl.payments.webhook');
    }

    public OwnershipAdoptionPlan $adoptionPlan;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PaymentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set([
            'nvl-payments.enabled' => true,
            'nvl-payments.ownership.scoped' => true,
            'nvl-payments.migrations.enabled' => false,
            'nvl-payments.stripe.secret' => 'sk_test_payments',
            'nvl-payments.stripe.webhook_secret' => 'whsec_payments',
            'nvl-payments.stripe.account_id' => 'acct_native',
            'nvl-payments.stripe.livemode' => false,
            'nvl-payments.allowed_currencies' => ['EUR'],
            'nvl-payments.checkout.return_hosts' => ['example.test'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->hasRetainedOwnershipSchema()) {
            foreach (glob(__DIR__.'/../database/migrations/payments/*.php') ?: [] as $path) {
                (require $path)->up();
            }
            (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        }
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            private bool $enabled = true;

            public function activate(array $payload): void
            {
                $this->enabled = true;
            }

            public function deactivate(): void
            {
                $this->enabled = false;
            }

            public function active(): bool
            {
                return $this->enabled;
            }

            public function data(): array
            {
                return [];
            }
        });
        if (! $this->hasRetainedOwnershipSchema()) {
            $this->adoptSchema();
        }
        $this->app->make(MaintenanceMode::class)->deactivate();
        $this->allowOwners();
    }

    private function adoptSchema(): void
    {
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $operation = new OwnershipOperation('payments-adopt', 'operator', '1');
        $plan = $coordinator->prepare(['payments'], $this->adoptionMappings(), $operation);
        while (! $coordinator->backfill($plan, 100, $operation)) {
        }
        $this->adoptionPlan = $plan;
        $this->activatePreparedPlan($coordinator, $plan, $operation);
    }

    /** Supply explicit reviewed pre-adoption records for nonempty cutover fixtures.
     * @return list<OwnershipMapping>
     */
    protected function adoptionMappings(): array
    {
        return [];
    }

    /** Activate the prepared fixture by default; mapping-review fixtures can inspect the unactivated graph. */
    protected function activatePreparedPlan(OwnershipAdoptionCoordinator $coordinator, OwnershipAdoptionPlan $plan, OwnershipOperation $operation): void
    {
        $coordinator->activate($plan, $operation);
    }
}
