<?php

declare(strict_types=1);

namespace Nvl\Payments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Services\DenyPaymentManagementAccess;
use Nvl\Payments\Services\DenyPaymentOrderProvider;
use PDOException;

/** Reports local deployment prerequisites using boolean checks without disclosing secrets. */
final class PaymentsDoctorCommand extends Command
{
    protected $signature = 'nvl:payments:doctor {--strict} {--format=text}';

    protected $description = 'Check local Payments configuration, schema, and webhook readiness';

    /** Report local readiness without contacting Stripe or printing configuration values. */
    public function handle(PaymentOrderProvider $orders, PaymentManagementAccess $access): int
    {
        $format = $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('Format must be text or json.');

            return self::FAILURE;
        }
        $checks = [
            'enabled' => config('payments.enabled') === true,
            'stripe_secret' => is_string(config('payments.stripe.secret')) && trim(config('payments.stripe.secret')) !== '',
            'webhook_secret' => is_string(config('payments.stripe.webhook_secret')) && trim(config('payments.stripe.webhook_secret')) !== '',
            'stripe_account' => is_string(config('payments.stripe.account_id')) && str_starts_with(config('payments.stripe.account_id'), 'acct_'),
            'stripe_mode' => is_bool(config('payments.stripe.livemode')),
            'currencies' => is_array(config('payments.allowed_currencies')) && config('payments.allowed_currencies') !== [],
            'return_hosts' => is_array(config('payments.checkout.return_hosts')) && config('payments.checkout.return_hosts') !== [],
            'capture_method' => in_array(config('payments.checkout.capture_method'), ['automatic', 'manual'], true),
            'order_provider' => ! $orders instanceof DenyPaymentOrderProvider,
            'management_access' => ! $access instanceof DenyPaymentManagementAccess,
            'webhook_route' => Route::has('nvl.payments.webhook'),
        ];
        try {
            $schema = (new PaymentAttempt)->getConnection()->getSchemaBuilder();
            foreach ([PaymentsTables::Attempts, PaymentsTables::Operations, PaymentsTables::Refunds, PaymentsTables::WebhookEvents] as $table) {
                $checks['schema_'.$table] = $schema->hasTable($table);
            }
        } catch (QueryException|PDOException) {
            $checks['schema_connection'] = false;
        }
        if ($format === 'json') {
            $this->line(json_encode(['checks' => $checks], JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $ready) {
                $this->line($name.': '.($ready ? 'ready' : 'missing or invalid'));
            }
            $this->line('Stripe endpoint registration and delivery must be verified in Stripe.');
        }

        return $this->option('strict') && in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
