<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;

/** Boots Payments with its vendor migrations for schema tests. */
class PaymentsSchemaTestCase extends PaymentsTestCase
{
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
