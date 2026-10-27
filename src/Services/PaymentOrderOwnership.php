<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Ownership\PaymentsIntegrityGuard;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Binds trusted order facts to actual producer admission without treating an order claim as authority.
 *
 * @internal
 */
final readonly class PaymentOrderOwnership
{
    /** Retain neutral runtime capabilities, never a cached owner or host order. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipContextResolver $context,
        private OwnershipBoundary $boundary, private PaymentsIntegrityGuard $integrity) {}

    /** Reject foreign or omitted native order ownership before host callbacks and provider effects. */
    public function assertOrder(OrderPaymentSnapshot $order, Authenticatable $actor): void
    {
        if (! $this->native()) {
            return;
        }
        $snapshot = $this->context->current()->snapshot();
        $id = $actor->getAuthIdentifier();
        if ($order->owner === null || $snapshot->owner != $order->owner || (! is_string($id) && ! is_int($id))
            || $snapshot->principal !== ['kind' => 'actor', 'type' => $actor::class, 'id' => (string) $id]) {
            throw new OwnershipViolation('The host order and actor must match the admitted native producer.');
        }
        $this->integrity->assertReady();
    }

    /** Preserve an existing legacy reservation; partition only newly created native reservations. */
    public function reservationKey(string $orderReference): string
    {
        if (! $this->native()) {
            return hash('sha256', $orderReference);
        }
        $existing = PaymentsModelRegistry::attemptQuery()->where('order_reference', $orderReference)->whereNotNull('reservation_key')->first();

        return $existing->reservation_key ?? hash('sha256', $this->boundary->key('payments.attempts', 'checkout:'.$orderReference));
    }

    /** Identify declaration metadata without granting access to adopted unscoped storage. */
    private function native(): bool
    {
        return $this->resources->get('payments.attempts')->ownership->strategy !== 'unscoped';
    }
}
