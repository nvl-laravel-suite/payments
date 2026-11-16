<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Exceptions\ProviderRefused;
use Nvl\Payments\Jobs\ProcessPaymentWebhook;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Stripe\Event;
use Throwable;

/** @internal */
final class PaymentWebhookInbox
{
    public function __construct(private readonly WebhookClaimLock $lock) {}

    public function receive(Event $event, bool $defer, ?PaymentWebhookProcessor $inlineProcessor = null): void
    {
        $outcome = $this->unavailableReason($event);
        $data = [
            'stripe_event_id' => $event->id,
            'type' => $event->type,
            'stripe_object_id' => $event->data->object->id,
            'stripe_account_id' => $event['account'],
            'stripe_livemode' => $event->livemode,
            'stripe_created_at' => $event['created'],
        ];
        self::query()->insertOrIgnore($data + [
            'status' => $outcome === null ? 'pending' : 'ignored', 'outcome' => $outcome,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stored = self::query()->where('stripe_event_id', $event->id)->sole();
        foreach ($data as $field => $value) {
            $actual = match ($field) {
                'stripe_livemode' => (bool) $stored->stripe_livemode,
                'stripe_created_at' => $this->timestamp($stored->stripe_created_at),
                default => $stored->{$field},
            };
            if ($actual !== $value) {
                throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'A Stripe event cannot change its recorded identity.');
            }
        }
        if (in_array($stored->status, ['pending', 'failed'], true) && $this->claimable()->where('stripe_event_id', $event->id)->exists()) {
            $this->dispatch($event->id, $defer, $inlineProcessor);
        }
    }

    private function dispatch(string $id, bool $defer, ?PaymentWebhookProcessor $inlineProcessor = null): void
    {
        $configured = config('nvl-payments.webhooks.connection');
        $connection = is_string($configured) ? $configured : null;
        $queue = config()->string('nvl-payments.webhooks.queue', 'payments');
        $driver = config('queue.connections.'.($connection ?? config()->string('queue.default')).'.driver');
        if ($defer && ($driver === 'sync' || $driver === 'null' || $driver === null)) {
            // The recovery command can enqueue this receipt when an asynchronous queue is available.
            return;
        }
        if ($driver === 'sync') {
            $this->handle($id, $inlineProcessor);

            return;
        }
        try {
            $job = (new ProcessPaymentWebhook($id, app(OwnershipQueueContext::class)->captureUnowned()))->onConnection($connection)->onQueue($queue);
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $failure) {
            Log::warning('payments.webhook_dispatch_failed', ['event_id' => $id, 'exception_type' => $failure::class]);
        }
    }

    /** Re-enqueue bounded pending or retryable failed receipts. */
    public function sweep(int $limit): int
    {
        PaymentsAvailability::assertOutsideTransaction();
        if (config('nvl-payments.enabled') !== true) {
            return 0;
        }
        $claimable = $this->claimable();
        $ids = self::query()->where(fn (Builder $query) => $query->whereIn('stripe_event_id', $claimable->select('stripe_event_id'))
            ->orWhere(fn (Builder $stale) => $stale->where('status', 'processing')->where('updated_at', '<=', $this->staleBefore())))
            ->orderBy('attempts')->orderBy('updated_at')->orderBy('stripe_event_id')->limit(max(1, min(1000, $limit)))->pluck('stripe_event_id');
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new \UnexpectedValueException('Malformed Payments webhook event ID.');
            }
            try {
                $this->dispatch($id, false);
            } catch (Throwable) {
                // Inline worker mode records the failure before throwing.
            }
        }

        return $ids->count();
    }

    public function handle(string $id, ?PaymentWebhookProcessor $inlineProcessor = null): void
    {
        PaymentsAvailability::assertOutsideTransaction();
        if (config('nvl-payments.enabled') !== true) {
            return;
        }
        $this->lock->run($id, fn () => $this->applyClaim($id, $inlineProcessor));
    }

    private function applyClaim(string $id, ?PaymentWebhookProcessor $inlineProcessor): void
    {
        self::query()->where('stripe_event_id', $id)->where('status', 'processing')->where('updated_at', '<=', $this->staleBefore())
            ->update(['status' => 'failed', 'outcome' => 'worker_interrupted', 'updated_at' => now()]);
        if ($this->claimable()->where('stripe_event_id', $id)->increment('attempts', 1, ['status' => 'processing', 'updated_at' => now()]) !== 1) {
            return;
        }
        $receipt = self::query()->where('stripe_event_id', $id)->sole();
        $event = Event::constructFrom([
            'id' => $id, 'type' => $receipt->type, 'account' => $receipt->stripe_account_id,
            'livemode' => (bool) $receipt->stripe_livemode, 'created' => $this->timestamp($receipt->stripe_created_at), 'data' => ['object' => ['id' => $receipt->stripe_object_id]],
        ]);
        try {
            $reason = $this->unavailableReason($event);
            $status = $reason === null ? ($inlineProcessor ?? app(PaymentWebhookProcessor::class))->apply($event) : 'ignored';
            self::query()->where('stripe_event_id', $id)->update(['status' => $status, 'outcome' => $reason, 'updated_at' => now()]);
        } catch (Throwable $failure) {
            self::query()->where('stripe_event_id', $id)->update([
                'status' => 'failed', 'outcome' => $failure instanceof ProviderRefused ? 'provider_refused' : 'processing_failed', 'updated_at' => now(),
            ]);
            Log::warning('payments.webhook_processing_failed', ['event_id' => $id, 'event_type' => $event->type, 'attempts' => $receipt->attempts, 'exception_type' => $failure::class]);

            throw $failure;
        }
    }

    private function unavailableReason(Event $event): ?string
    {
        return match (true) {
            $event->livemode && config('nvl-payments.allow_live') !== true => 'live_mode_disallowed',
            $event->livemode !== config('nvl-payments.stripe.livemode') => 'wrong_mode',
            $event['account'] !== null && $event['account'] !== config('nvl-payments.stripe.account_id') => 'wrong_account',
            ! in_array($event->type, PaymentWebhooks::eventTypes(), true) => 'unsupported_event',
            default => null,
        };
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Malformed provider event timestamp.');
    }

    private function staleBefore(): Carbon
    {
        return now()->subMinutes(max(1, config()->integer('nvl-payments.webhooks.stale_after_minutes', 5)));
    }

    private function claimable(): Builder
    {
        return self::query()->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', max(1, config()->integer('nvl-payments.webhooks.max_attempts', 5)))
            ->where(fn (Builder $query) => $query->whereNull('outcome')->orWhere('outcome', '<>', 'provider_refused'));
    }

    public static function query(): Builder
    {
        return PaymentsModelRegistry::newAttempt()->getConnection()->table(PaymentsTables::get(PaymentsTables::WebhookInbox))->useWritePdo();
    }
}
