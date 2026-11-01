<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use PDOException;

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
            foreach ([PaymentsTables::get(PaymentsTables::Attempts), PaymentsTables::get(PaymentsTables::Operations), PaymentsTables::get(PaymentsTables::Refunds), PaymentsTables::get(PaymentsTables::WebhookEvents)] as $table) {
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

        return $checks;
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
