<?php

declare(strict_types=1);

namespace Nvl\Payments\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Nvl\Payments\Actions\AttachExistingPaymentAction;
use Nvl\Payments\Actions\CancelAuthorizationAction;
use Nvl\Payments\Actions\CapturePaymentAction;
use Nvl\Payments\Actions\RecoverCheckoutAction;
use Nvl\Payments\Actions\RefundPaymentAction;
use Nvl\Payments\Actions\ResolvePaymentExceptionAction;
use Nvl\Payments\Actions\StartCheckoutAction;
use Nvl\Payments\Console\Commands\PaymentsDoctorCommand;
use Nvl\Payments\Console\Commands\PaymentsReconcileCommand;
use Nvl\Payments\Contracts\AttachExistingPaymentContract;
use Nvl\Payments\Contracts\CancelAuthorizationContract;
use Nvl\Payments\Contracts\CapturePaymentContract;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Contracts\PaymentReadContract;
use Nvl\Payments\Contracts\RecoverCheckoutContract;
use Nvl\Payments\Contracts\RefundPaymentContract;
use Nvl\Payments\Contracts\ResolvePaymentExceptionContract;
use Nvl\Payments\Contracts\StartCheckoutContract;
use Nvl\Payments\Services\DenyExistingPaymentOwnership;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Services\DenyPaymentOrderProvider;
use Nvl\Payments\Services\PaymentReadService;
use Nvl\Payments\Services\PaymentsDoctor;
use Nvl\Payments\Services\StripePaymentGateway;
use Nvl\Support\Doctor\DoctorCheck;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Stripe\StripeClient;

/** Registers disabled-by-default Payments configuration and migration resources. */
final class PaymentsServiceProvider extends ServiceProvider
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

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-payments.php', 'payments');
        $this->app->bindIf(AttachExistingPaymentContract::class, AttachExistingPaymentAction::class);
        $this->app->bindIf(CancelAuthorizationContract::class, CancelAuthorizationAction::class);
        $this->app->bindIf(CapturePaymentContract::class, CapturePaymentAction::class);
        $this->app->bindIf(RecoverCheckoutContract::class, RecoverCheckoutAction::class);
        $this->app->bindIf(RefundPaymentContract::class, RefundPaymentAction::class);
        $this->app->bindIf(ResolvePaymentExceptionContract::class, ResolvePaymentExceptionAction::class);
        $this->app->bindIf(StartCheckoutContract::class, StartCheckoutAction::class);
        $this->app->bindIf(PaymentReadContract::class, PaymentReadService::class);
        $this->app->bindIf(PaymentGateway::class, fn (): StripePaymentGateway => new StripePaymentGateway(
            new StripeClient(Config::string('nvl-payments.stripe.secret')),
            Config::string('nvl-payments.stripe.account_id'),
            Config::boolean('nvl-payments.stripe.livemode'),
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
            __DIR__.'/../../config/nvl-payments.php' => config_path('nvl-payments.php'),
        ], 'payments-config');
        $this->publishesMigrations([$path => database_path('migrations')], 'payments-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills/nvl-payments' => base_path('.agents/skills/nvl-payments'),
        ], 'payments-skills');

        if (config('nvl-payments.enabled') === true) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        }

        if (config('nvl-payments.migrations.enabled') === true) {
            $this->loadMigrationsFrom($path);
        }
    }
}
