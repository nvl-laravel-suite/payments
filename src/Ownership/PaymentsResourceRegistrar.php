<?php

declare(strict_types=1);

namespace Nvl\Payments\Ownership;

use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentWebhookEvent;
use Nvl\Payments\Services\PaymentsModelRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Declares independently captured payment facts and inherited refund ownership.
 *
 * @internal
 */
final readonly class PaymentsResourceRegistrar
{
    /** Register the exact owned graph without requiring a host order table or tenant lifecycle. */
    public function register(OwnershipResourceRegistry $resources, bool $owned): void
    {
        $resources->register(new OwnershipResourceDefinition('payments.attempts', 'payments', PaymentsModelRegistry::attemptClass(),
            $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(),
            immutableFields: $owned ? ['order_reference', 'order_revision', 'amount_minor', 'currency', 'origin'] : []));
        $resources->register(new OwnershipResourceDefinition('payments.operations', 'payments', PaymentOperation::class,
            $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(),
            immutableFields: $owned ? ['order_reference', 'type', 'actor_reference', 'idempotency_key', 'input_fingerprint'] : []));
        $resources->register(new OwnershipResourceDefinition('payments.refunds', 'payments', PaymentsModelRegistry::refundClass(),
            $owned ? OwnershipDefinition::inherited('paymentAttempt') : OwnershipDefinition::unscoped(), parentResource: $owned ? 'payments.attempts' : null,
            immutableFields: $owned ? ['payment_operation_id', 'amount_minor', 'currency'] : []));
        $resources->register(new OwnershipResourceDefinition('payments.webhooks', 'payments', PaymentWebhookEvent::class,
            $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(), allowsCentral: $owned,
            immutableFields: $owned ? ['stripe_event_id', 'type', 'stripe_object_id', 'stripe_account_id', 'stripe_livemode'] : []));
    }
}
