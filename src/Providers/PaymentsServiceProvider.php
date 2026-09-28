<?php

declare(strict_types=1);

namespace Nvl\Payments\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Nvl\Payments\Console\Commands\PaymentsDoctorCommand;
use Nvl\Payments\Console\Commands\PaymentsReconcileCommand;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Services\DenyExistingPaymentOwnership;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Services\DenyPaymentOrderProvider;
use Nvl\Payments\Services\StripePaymentGateway;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Stripe\StripeClient;

/** Registers disabled-by-default Payments configuration and migration resources. */
final class PaymentsServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /** Merge publishable Payments configuration without activating the package. */
    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/payments.php', 'payments');
        $this->app->bindIf(PaymentGateway::class, fn (): StripePaymentGateway => new StripePaymentGateway(
            new StripeClient(Config::string('payments.stripe.secret')),
            Config::string('payments.stripe.account_id'),
            Config::boolean('payments.stripe.livemode'),
        ));
        $this->app->bindIf(PaymentOrderProvider::class, DenyPaymentOrderProvider::class);
        $this->app->bindIf(PaymentManagementAccess::class, DenyPaymentManagementAccess::class);
        $this->app->bindIf(ExistingPaymentOwnership::class, DenyExistingPaymentOwnership::class);
    }

    /** Publish resources and load vendor migrations only when explicitly enabled. */
    public function boot(): void
    {
        $this->commands([PaymentsDoctorCommand::class, PaymentsReconcileCommand::class]);
        $path = __DIR__.'/../../database/migrations/payments';

        $this->publishes([
            __DIR__.'/../../config/payments.php' => config_path('payments.php'),
        ], 'payments-config');
        $this->publishesMigrations([$path => database_path('migrations')], 'payments-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills/nvl-payments' => base_path('.agents/skills/nvl-payments'),
        ], 'payments-skills');

        if (config('payments.enabled') === true) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        }

        if (config('payments.migrations.enabled') === true) {
            $this->loadMigrationsFrom($path);
        }
    }
}
