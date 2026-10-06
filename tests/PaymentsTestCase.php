<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests;

use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Payments\Providers\PaymentsServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase;

/** Boots Payments with disabled defaults against an isolated database. */
class PaymentsTestCase extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class, SupportServiceProvider::class, DataServiceProvider::class, PaymentsServiceProvider::class];
    }

    /** Configure an isolated default database without enabling Payments. */
    protected function defineEnvironment($app): void
    {
        $driver = getenv('NVL_FULL_DATABASE') === '1' ? (getenv('DB_CONNECTION') ?: 'sqlite') : 'sqlite';
        $database = $driver === 'sqlite' ? ':memory:' : (getenv('DB_DATABASE') ?: 'testing');

        $app['config']->set([
            'database.default' => $driver,
            'database.connections.'.$driver.'.database' => $database,
            'database.connections.'.$driver.'.url' => null,
        ]);
    }
}
