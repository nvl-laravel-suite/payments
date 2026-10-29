<?php

declare(strict_types=1);

namespace Nvl\Payments\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Payments\Console\Commands\PaymentsDoctorCommand;
use Nvl\Payments\Console\Commands\PaymentsReconcileCommand;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Contracts\PaymentOwnerWorklist;
use Nvl\Payments\Ownership\PaymentsAdoptionAdapter;
use Nvl\Payments\Ownership\PaymentsResourceRegistrar;
use Nvl\Payments\Ownership\StoredPaymentOwnerWorklist;
use Nvl\Payments\Services\DenyExistingPaymentOwnership;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Services\DenyPaymentOrderProvider;
use Nvl\Payments\Services\PaymentsDoctor;
use Nvl\Payments\Services\PaymentsModelRegistry;
use Nvl\Payments\Services\StripePaymentGateway;
use Nvl\Support\Bindings\RequiredBindingDefinition;
use Nvl\Support\Bindings\RequiredBindings;
use Nvl\Support\Doctor\DoctorCheck;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Stripe\StripeClient;

/** Registers disabled-by-default Payments configuration and migration resources. */
final class PaymentsServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /** Merge publishable Payments configuration without activating the package. */
    public function register(): void
    {
        PackageDoctorContributor::register($this->app, 'nvl/payments', function (): array {
            if (config('nvl-payments.enabled') !== true) {
                return [new DoctorCheck('enabled', 'info', true, 'Payments is disabled; enable it explicitly before configuring its integrations.')];
            }

            $report = $this->app->make(PaymentsDoctor::class)->inspect();

            return PackageDoctorContributor::booleanChecks($report, 'nvl:payments:doctor');
        });

        $this->app->booted(function (): void {
            (new PaymentsResourceRegistrar)->register($this->app->make(OwnershipResourceRegistry::class), config('nvl-ownership.enabled') === true && config('nvl-payments.ownership.scoped', true) === true);
            if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
                $this->app->make(OwnershipAdoptionRegistry::class)->register('payments', PaymentsAdoptionAdapter::class);
            }
        });
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-payments.php', 'payments');
        $this->app->singleton(PaymentsModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(PaymentsModelRegistry::class)->prepare());
        $this->app->bindIf(PaymentGateway::class, fn (): StripePaymentGateway => new StripePaymentGateway(
            new StripeClient(Config::string('nvl-payments.stripe.secret')),
            Config::string('nvl-payments.stripe.account_id'),
            Config::boolean('nvl-payments.stripe.livemode'),
        ));
        $this->app->bindIf(PaymentOwnerWorklist::class, StoredPaymentOwnerWorklist::class);
        $this->app->bindIf(PaymentOrderProvider::class, DenyPaymentOrderProvider::class);
        $this->app->bindIf(PaymentManagementAccess::class, DenyPaymentManagementAccess::class);
        $this->app->bindIf(ExistingPaymentOwnership::class, DenyExistingPaymentOwnership::class);
        $this->callAfterResolving(RequiredBindings::class, static function (RequiredBindings $bindings): void {
            $documentation = 'https://github.com/nvl-laravel-suite/payments#required-bindings';
            $bindings->register(new RequiredBindingDefinition('payments', PaymentOrderProvider::class, DenyPaymentOrderProvider::class, 'order_resolution', 'nvl-payments.enabled', $documentation));
            $bindings->register(new RequiredBindingDefinition('payments', PaymentManagementAccess::class, DenyPaymentManagementAccess::class, 'payment_management', 'nvl-payments.enabled', $documentation));
            $bindings->register(new RequiredBindingDefinition('payments', ExistingPaymentOwnership::class, DenyExistingPaymentOwnership::class, 'existing_payment_attachment', 'nvl-payments.enabled', $documentation));
        });
    }

    /** Publish resources and load vendor migrations only when explicitly enabled. */
    public function boot(): void
    {
        $this->app->make(GlobalNames::class)->translations('payments', __DIR__.'/../../lang', $this->app->make('translation.loader'));
        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/nvl-payments'),
        ], 'nvl-payments-translations');
        $this->commands([PaymentsDoctorCommand::class, PaymentsReconcileCommand::class]);
        $path = __DIR__.'/../../database/migrations/payments';

        $this->publishes([
            __DIR__.'/../../config/nvl-payments.php' => config_path('nvl-payments.php'),
        ], 'nvl-payments-config');
        $this->publishesMigrations([$path => database_path('migrations')], 'nvl-payments-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills/nvl-payments' => base_path('.agents/skills/nvl-payments'),
        ], 'nvl-payments-skills');

        if (config('nvl-payments.migrations.enabled') === true) {
            $this->loadMigrationsFrom($path);
        }
    }
}
