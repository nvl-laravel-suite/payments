<?php

declare(strict_types=1);

namespace Nvl\Payments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nvl\Payments\Actions\ReconcilePaymentAction;
use Nvl\Payments\Models\PaymentAttempt;
use Throwable;

/** Runs a targeted repair or a bounded, paginated sweep of payment attempts. */
final class PaymentsReconcileCommand extends Command
{
    protected $signature = 'nvl:payments:reconcile {--payment= : One attempt UUID}';

    protected $description = 'Reconcile order payments with authoritative Stripe facts';

    /** Repair attempts while reporting sanitized identifiers and outcomes only. */
    public function handle(ReconcilePaymentAction $reconcile): int
    {
        if (! config()->boolean('nvl-payments.enabled')) {
            $this->error('Payments is disabled.');

            return self::FAILURE;
        }
        $target = $this->option('payment');
        if ($target !== null && ! Str::isUuid($target)) {
            $this->error('Payment must be an attempt UUID.');

            return self::FAILURE;
        }
        $batch = max(1, min(1000, config()->integer('nvl-payments.reconciliation.batch_size', 100)));
        $maximum = max(1, min(10000, config()->integer('nvl-payments.reconciliation.max_attempts', 1000)));
        $query = PaymentAttempt::query();
        if ($target !== null) {
            $query->whereKey($target);
        }
        $processed = 0;
        $failed = 0;
        $visited = [];
        while (count($visited) < $maximum) {
            $ids = (clone $query)->whereNotIn('id', $visited)->orderByRaw('CASE WHEN last_reconcile_attempt_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_reconcile_attempt_at')->orderBy('id')
                ->limit(min($batch, $maximum - count($visited)))->pluck('id')->filter(static fn (mixed $id): bool => is_string($id))->values();
            if ($ids->isEmpty()) {
                break;
            }
            foreach ($ids as $id) {
                $visited[] = $id;
                try {
                    PaymentAttempt::query()->whereKey($id)->update(['last_reconcile_attempt_at' => now()]);
                    $reconcile->execute($id);
                    $processed++;
                } catch (Throwable) {
                    $failed++;
                    $this->error('Reconciliation failed for attempt '.$id.'.');
                }
            }
        }
        $this->info($processed.' reconciled; '.$failed.' failed.');

        return $failed > 0 || ($target !== null && $processed === 0) ? self::FAILURE : self::SUCCESS;
    }
}
