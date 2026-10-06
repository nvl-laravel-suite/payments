<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Models\PaymentAttempt;
use PDOException;

/** Reports local deployment prerequisites using boolean checks without disclosing secrets. */
final class PaymentsDoctor
{
    /** Initialize the package-owned inspection dependencies. */
    public function __construct(
        private PaymentOrderProvider $orders,
        private PaymentManagementAccess $access,
    ) {}

    /**
     * Inspect package readiness without rendering a command or changing state.
     *
     * @return array<string, bool>
     */
    public function inspect(): array
    {
        $orders = $this->orders;
        $access = $this->access;

        $checks = [
            'enabled' => config('nvl-payments.enabled') === true,
            'stripe_secret' => is_string(config('nvl-payments.stripe.secret')) && trim(config('nvl-payments.stripe.secret')) !== '',
            'webhook_secret' => is_string(config('nvl-payments.stripe.webhook_secret')) && trim(config('nvl-payments.stripe.webhook_secret')) !== '',
            'stripe_account' => is_string(config('nvl-payments.stripe.account_id')) && str_starts_with(config('nvl-payments.stripe.account_id'), 'acct_'),
            'stripe_mode' => is_bool(config('nvl-payments.stripe.livemode')),
            'currencies' => is_array(config('nvl-payments.allowed_currencies')) && config('nvl-payments.allowed_currencies') !== [],
            'return_hosts' => is_array(config('nvl-payments.checkout.return_hosts')) && config('nvl-payments.checkout.return_hosts') !== [],
            'capture_method' => in_array(config('nvl-payments.checkout.capture_method'), ['automatic', 'manual'], true),
            'order_provider' => ! $orders instanceof DenyPaymentOrderProvider,
            'management_access' => ! $access instanceof DenyPaymentManagementAccess,
            'webhook_route' => Route::has('nvl.payments.webhook'),
        ];
        try {
            $schema = (new PaymentAttempt)->getConnection()->getSchemaBuilder();
            foreach ([PaymentsTables::get(PaymentsTables::Attempts), PaymentsTables::get(PaymentsTables::Operations), PaymentsTables::get(PaymentsTables::Refunds), PaymentsTables::get(PaymentsTables::WebhookEvents)] as $table) {
                $checks['schema_'.$table] = $schema->hasTable($table);
            }
        } catch (QueryException|PDOException) {
            $checks['schema_connection'] = false;
        }

        return $checks;
    }
}
