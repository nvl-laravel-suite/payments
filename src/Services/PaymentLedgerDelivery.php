<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Nvl\Payments\Contracts\PaymentLedgerProjector;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Ledger\PaymentCanceled;
use Nvl\Payments\Ledger\PaymentExceptionRaised;
use Nvl\Payments\Ledger\PaymentFailed;
use Nvl\Payments\Ledger\PaymentSucceeded;
use Nvl\Payments\Ledger\RefundCanceled;
use Nvl\Payments\Ledger\RefundFailed;
use Nvl\Payments\Ledger\RefundPending;
use Nvl\Payments\Ledger\RefundSucceeded;
use ReflectionClass;
use Throwable;
use UnexpectedValueException;

/**
 * Retry pending host deliveries or replay a particular fact by its stable ID.
 *
 * @api
 */
class PaymentLedgerDelivery
{
    /**
     * Deliver a saved fact, including one previously acknowledged when replay is requested.
     *
     * @phpstan-impure
     */
    public function deliver(string $id, bool $replay = false): bool
    {
        PaymentsAvailability::assertOutsideTransaction();
        if (! app()->bound(PaymentLedgerProjector::class)) {
            return false;
        }
        $query = self::query();
        $row = (clone $query)->where('id', $id)->first();
        if ($row === null || (! $replay && $row->delivered_at !== null) || ($row->delivered_at === null && (clone $query)
            ->where('attempt_id', $row->attempt_id)->where('sequence', '<', $row->sequence)->whereNull('delivered_at')->exists())) {
            return false;
        }
        if (! is_string($row->facts) || ! is_array($data = json_decode($row->facts, true, flags: JSON_THROW_ON_ERROR))) {
            throw new UnexpectedValueException('Malformed Payments ledger fact.');
        }
        $type = match ($row->type) {
            'payment_succeeded_v1' => PaymentSucceeded::class,
            'payment_failed_v1' => PaymentFailed::class,
            'payment_canceled_v1' => PaymentCanceled::class,
            'payment_exception_raised_v1' => PaymentExceptionRaised::class,
            'refund_pending_v1' => RefundPending::class,
            'refund_succeeded_v1' => RefundSucceeded::class,
            'refund_failed_v1' => RefundFailed::class,
            'refund_canceled_v1' => RefundCanceled::class,
            default => throw new UnexpectedValueException('Unrecognized Payments ledger fact version.'),
        };
        $fact = (new ReflectionClass($type))->newInstanceArgs($data);
        (clone $query)->where('id', $id)->update(['last_delivery_attempt_at' => now()]);
        app(PaymentLedgerProjector::class)->project($fact);
        $query->where('id', $id)->whereNull('delivered_at')->update(['delivered_at' => now()]);

        return true;
    }

    /** Try the oldest pending fact per attempt, up to the requested batch size. */
    public function retry(int $limit = 100): int
    {
        PaymentsAvailability::assertOutsideTransaction();
        if (! app()->bound(PaymentLedgerProjector::class)) {
            return 0;
        }
        $delivered = 0;
        $table = PaymentsTables::get(PaymentsTables::LedgerFacts);
        $ids = self::query()->from($table, 'ledger_next')->whereNull('ledger_next.delivered_at')
            ->whereNotExists(fn (Builder $prior) => $prior->selectRaw('1')->from($table, 'ledger_prior')
                ->whereColumn('ledger_prior.attempt_id', 'ledger_next.attempt_id')
                ->whereColumn('ledger_prior.sequence', '<', 'ledger_next.sequence')->whereNull('ledger_prior.delivered_at'))
            ->orderByRaw('CASE WHEN ledger_next.last_delivery_attempt_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('ledger_next.last_delivery_attempt_at')->orderBy('ledger_next.sequence')->limit(max(1, min($limit, 1000)))->pluck('ledger_next.id');
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new UnexpectedValueException('Malformed Payments ledger fact ID.');
            }
            try {
                $delivered += (int) $this->deliver($id);
            } catch (Throwable $failure) {
                Log::warning('payments.ledger_delivery_failed', ['fact_id' => $id, 'exception_type' => $failure::class]);
            }
        }

        return $delivered;
    }

    /** @internal */
    public static function query(): Builder
    {
        return PaymentsModelRegistry::newAttempt()->getConnection()->table(PaymentsTables::get(PaymentsTables::LedgerFacts))->useWritePdo();
    }
}
