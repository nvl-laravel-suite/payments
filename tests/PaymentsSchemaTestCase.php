<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Payments\Tests\Fixtures\PaymentsWebhookController;

/** Boots Payments with its vendor migrations for schema tests. */
class PaymentsSchemaTestCase extends PaymentsTestCase
{
    /** Install the consumer endpoint independently of the package provider. */
    protected function defineRoutes($router): void
    {
        $router->post('/nvl/payments/stripe/webhook', PaymentsWebhookController::class)->name('nvl.payments.webhook');
    }

    use DatabaseMigrations;

    /** Enable Payments and its vendor migrations before the provider boots. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'nvl-payments.enabled' => true,
            'nvl-payments.migrations.enabled' => true,
        ]);
    }
}
