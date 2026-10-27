<?php

declare(strict_types=1);

namespace Nvl\Payments\Ownership;

use Closure;
use Nvl\Payments\Models\PaymentWebhookEvent;
use Nvl\Payments\Services\PaymentsModelRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Stripe\Event;

/**
 * Resolves immutable stored payment identity only after the ingress verifies signature, account and mode.
 *
 * @internal
 */
final readonly class PaymentsProviderAdmission
{
    /** Retain fixed correlation inspection and named owner admission capabilities. */
    public function __construct(private OwnershipResourceRegistry $resources, private PaymentsIntegrityGuard $integrity, private OwnershipSystemRunner $runner) {}

    /**
     * Apply a verified delivery without borrowing ambient owner or request metadata.
     *
     * @param  Closure():void  $apply
     */
    public function run(Event $event, Closure $apply): void
    {
        if (! $this->native()) {
            $apply();

            return;
        }
        $this->integrity->assertReady();
        $object = $event->data->object->id;
        if (! is_string($object) || $object === '' || strlen($object) > 255) {
            throw new OwnershipViolation;
        }
        if ($this->historicalReceipt($event, $object)) {
            return;
        }
        $field = PaymentProviderCorrelation::field($event->type);
        if ($field === null) {
            return;
        }
        $model = PaymentsModelRegistry::newAttempt();
        $rows = $model->getConnection()->table($model->getTable())->useWritePdo()->where($field, $object)->limit(2)->get(['owner_scope_key', 'owner_reference']);
        if ($rows->isEmpty()) {
            return;
        }
        if ($rows->count() !== 1) {
            throw new OwnershipViolation('A provider correlation must identify one canonical payment.');
        }
        $row = $rows->first();
        if (! is_string($row->owner_reference)) {
            throw new OwnershipViolation;
        }
        $owner = OwnerReference::fromJson($row->owner_reference);
        if ($owner->scopeKey() !== $row->owner_scope_key) {
            throw new OwnershipViolation;
        }
        $this->runner->runSystem($owner, 'payments.provider-callback', $apply);
    }

    /** Reject attempts to reuse a verified event ID for different immutable external facts. */
    public function assertReceipt(PaymentWebhookEvent $receipt, Event $event): void
    {
        if (! $this->native()) {
            return;
        }
        if ($receipt->stripe_event_id !== $event->id || $receipt->type !== $event->type
            || $receipt->stripe_object_id !== $event->data->object->id || $receipt->stripe_account_id !== $event['account']
            || $receipt->stripe_livemode !== $event->livemode) {
            throw new OwnershipViolation('A verified provider event cannot change its captured external identity.');
        }
    }

    /** Acknowledge only previously processed explicit central history; pending work never gains an owner. */
    private function historicalReceipt(Event $event, string $object): bool
    {
        $model = new PaymentWebhookEvent;
        $row = $model->getConnection()->table($model->getTable())->useWritePdo()->where('stripe_event_id', $event->id)
            ->where('owner_scope_key', 'central')->whereNull('owner_reference')->first(['type', 'stripe_object_id', 'stripe_account_id', 'stripe_livemode', 'status']);
        if ($row === null) {
            return false;
        }
        if ($row->type !== $event->type || $row->stripe_object_id !== $object || $row->stripe_account_id !== $event['account']
            || ! in_array($row->stripe_livemode, [$event->livemode, (int) $event->livemode], true)
            || ! in_array($row->status, ['processed', 'ignored'], true)) {
            throw new OwnershipViolation('Historical provider work requires preserved identity and a completed outcome.');
        }

        return true;
    }

    /** Identify native metadata without silently declassifying adopted storage. */
    private function native(): bool
    {
        return $this->resources->get('payments.attempts')->ownership->strategy !== 'unscoped';
    }
}
