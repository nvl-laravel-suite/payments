<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use PDOException;
use Throwable;

/**
 * Reports local deployment prerequisites using boolean checks without disclosing secrets.
 *
 * @internal
 */
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
    public function inspect(bool $inspectStripe = false): array
    {
        $orders = $this->orders;
        $access = $this->access;

        $key = config('nvl-payments.stripe.secret');
        $configured = config('nvl-payments.webhooks.connection');
        $queueConnection = is_string($configured) ? $configured : config()->string('queue.default');
        $driver = config('queue.connections.'.$queueConnection.'.driver');
        $endpoint = config('nvl-payments.webhooks.url');
        $inspectStripe = $inspectStripe || (is_string($endpoint) && $endpoint !== '');
        $checks = [
            'enabled' => config('nvl-payments.enabled') === true,
            'stripe_secret' => is_string(config('nvl-payments.stripe.secret')) && trim(config('nvl-payments.stripe.secret')) !== '',
            'webhook_secret' => is_string(config('nvl-payments.stripe.webhook_secret')) && trim(config('nvl-payments.stripe.webhook_secret')) !== '',
            'stripe_account' => is_string(config('nvl-payments.stripe.account_id')) && str_starts_with(config('nvl-payments.stripe.account_id'), 'acct_'),
            'stripe_mode' => is_string($key) && PaymentsAvailability::allowsKey($key),
            'webhook_queue' => is_string($driver) && $driver !== 'null' && (! ($inspectStripe || config('nvl-payments.webhooks.route') === true) || $driver !== 'sync')
                && trim(config()->string('nvl-payments.webhooks.queue')) !== '' && config()->integer('nvl-payments.webhooks.max_attempts') > 0,
            'currencies' => is_array(config('nvl-payments.allowed_currencies')) && config('nvl-payments.allowed_currencies') !== [],
            'return_hosts' => (is_array(config('nvl-payments.checkout.return_hosts')) && config('nvl-payments.checkout.return_hosts') !== [])
                || (is_string($publishable = config('nvl-payments.stripe.publishable_key')) && str_starts_with($publishable, config('nvl-payments.stripe.livemode') === true ? 'pk_live_' : 'pk_test_')),
            'capture_method' => in_array(config('nvl-payments.checkout.capture_method'), ['automatic', 'automatic_async', 'manual'], true),
            'order_provider' => ! $orders instanceof DenyPaymentOrderProvider,
            'management_access' => ! $access instanceof DenyPaymentManagementAccess,
            'financial_attempt_columns' => false,
            'financial_refund_columns' => false,
            'financial_attempt_amounts' => false,
            'financial_refund_amounts' => false,
            'financial_refund_reservations' => false,
        ];
        try {
            $connection = (PaymentsModelRegistry::newAttempt())->getConnection();
            $schema = $connection->getSchemaBuilder();
            $attempts = PaymentsTables::get(PaymentsTables::Attempts);
            $refunds = PaymentsTables::get(PaymentsTables::Refunds);
            $columns = [];
            foreach ([PaymentsTables::get(PaymentsTables::Attempts), PaymentsTables::get(PaymentsTables::Operations), PaymentsTables::get(PaymentsTables::Refunds), PaymentsTables::get(PaymentsTables::WebhookEvents), PaymentsTables::get(PaymentsTables::WebhookInbox), PaymentsTables::get(PaymentsTables::LedgerFacts), PaymentsTables::get(PaymentsTables::Customers)] as $table) {
                $columns[$table] = array_map('strtolower', $schema->getColumnListing($table));
                $checks['schema_'.$table] = $columns[$table] !== [];
            }

            $checks['financial_attempt_columns'] = $checks['schema_'.$attempts]
                && array_diff(['id', 'amount_minor', 'captured_amount_minor', 'refunded_amount_minor'], $columns[$attempts]) === [];
            $checks['financial_refund_columns'] = $checks['schema_'.$refunds]
                && array_diff(['payment_attempt_id', 'amount_minor', 'status'], $columns[$refunds]) === [];

            if ($checks['financial_attempt_columns']) {
                $checks['financial_attempt_amounts'] = $this->attemptAmountsValid($connection, $attempts);
            }

            if ($checks['financial_attempt_columns'] && $checks['financial_refund_columns']) {
                $checks['financial_refund_amounts'] = $this->refundAmountsValid($connection, $attempts, $refunds);
                $checks['financial_refund_reservations'] = $this->refundReservationsValid($connection, $attempts, $refunds);
            }
        } catch (QueryException|PDOException) {
            $checks['schema_connection'] = false;
        }

        if ($inspectStripe) {
            $checks += ['stripe_country' => false, 'stripe_default_currency' => false, 'stripe_webhook_registration' => false];
            if ($checks['enabled'] && $checks['stripe_mode'] && $checks['stripe_account']) {
                try {
                    /** @var PaymentGateway $gateway Hosts may bind fakes or other adapters. */
                    $gateway = app(PaymentGateway::class);
                    if ($gateway instanceof StripePaymentGateway) {
                        $checks = array_replace($checks, $gateway->inspectStripe(is_string($endpoint) && $endpoint !== '' ? $endpoint : null, PaymentWebhooks::eventTypes()));
                    }
                } catch (Throwable) {
                    // Provider failures are logged by the adapter without exposing credentials.
                }
            }
        }

        return $checks;
    }

    /** @return array<string,int> */
    public function inboxBacklog(): array
    {
        $counts = array_fill_keys(['pending', 'processing', 'failed', 'ignored', 'processed'], 0);
        try {
            foreach (PaymentWebhookInbox::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get() as $row) {
                if (is_string($row->status) && isset($counts[$row->status]) && (is_int($row->total) || (is_string($row->total) && ctype_digit($row->total)))) {
                    $counts[$row->status] = (int) $row->total;
                }
            }
        } catch (QueryException|PDOException) {
            return [];
        }

        return $counts;
    }

    /** Check persisted monetary bounds on the writer without loading private payment records. */
    private function attemptAmountsValid(Connection $connection, string $attempts): bool
    {
        return ! $connection->table($attempts)->useWritePdo()->where(function (Builder $query): void {
            $query->whereNull('amount_minor')
                ->orWhere('amount_minor', '<=', 0)
                ->orWhereNull('captured_amount_minor')
                ->orWhere('captured_amount_minor', '<', 0)
                ->orWhereNull('refunded_amount_minor')
                ->orWhere('refunded_amount_minor', '<', 0)
                ->orWhereColumn('captured_amount_minor', '>', 'amount_minor')
                ->orWhereColumn('refunded_amount_minor', '>', 'captured_amount_minor');
        })->exists();
    }

    /** Check each refund against its original payment independently of provider synchronization lag. */
    private function refundAmountsValid(Connection $connection, string $attempts, string $refunds): bool
    {
        return ! $connection->table($refunds, 'diagnostic_refunds')->useWritePdo()
            ->leftJoin($attempts.' as diagnostic_attempts', 'diagnostic_attempts.id', '=', 'diagnostic_refunds.payment_attempt_id')
            ->where(function (Builder $query): void {
                $query->whereNull('diagnostic_refunds.amount_minor')
                    ->orWhere('diagnostic_refunds.amount_minor', '<=', 0)
                    ->orWhereNull('diagnostic_attempts.id')
                    ->orWhereColumn('diagnostic_refunds.amount_minor', '>', 'diagnostic_attempts.amount_minor');
            })->exists();
    }

    /** Check the local live reservation ceiling; provider agreement still requires reconciliation. */
    private function refundReservationsValid(Connection $connection, string $attempts, string $refunds): bool
    {
        return ! $connection->table($attempts, 'diagnostic_attempts')->useWritePdo()
            ->where('diagnostic_attempts.amount_minor', '<', function (Builder $query) use ($refunds): void {
                $query->from($refunds, 'diagnostic_refunds')
                    ->selectRaw('COALESCE(SUM(amount_minor), 0)')
                    ->whereColumn('diagnostic_refunds.payment_attempt_id', 'diagnostic_attempts.id')
                    ->whereNotIn('diagnostic_refunds.status', ['failed', 'canceled']);
            })
            ->exists();
    }
}
