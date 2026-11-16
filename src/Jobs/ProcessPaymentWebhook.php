<?php

declare(strict_types=1);

namespace Nvl\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Nvl\Payments\Services\PaymentWebhookInbox;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** @internal */
final class ProcessPaymentWebhook implements OwnershipQueuedJob, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $eventId, private readonly OwnershipJobEnvelope $envelope) {}

    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->envelope;
    }

    public function handle(PaymentWebhookInbox $inbox): void
    {
        $inbox->handle($this->eventId);
    }
}
