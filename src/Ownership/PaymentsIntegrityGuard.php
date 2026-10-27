<?php

declare(strict_types=1);

namespace Nvl\Payments\Ownership;

use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\PaymentsModelRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Requires live immutable provider and native financial graph constraints before effects.
 *
 * @internal
 */
final readonly class PaymentsIntegrityGuard
{
    public const array Unique = [
        'payments.attempts' => [['reservation_key'], ['stripe_checkout_session_id'], ['stripe_payment_intent_id'], ['stripe_charge_id']],
        'payments.operations' => [['idempotency_key']],
        'payments.refunds' => [['payment_operation_id'], ['stripe_refund_id']],
        'payments.webhooks' => [['stripe_event_id']],
    ];

    public const array SettledChargeStates = ['captured', 'partially_refunded', 'refunded'];

    public const array Assigned = [
        'payments.attempts' => ['stripe_checkout_session_id', 'stripe_payment_intent_id', 'stripe_account_id', 'stripe_livemode', 'capture_method'],
        'payments.operations' => ['payment_attempt_id', 'stripe_result_reference'],
        'payments.refunds' => ['stripe_refund_id'],
    ];

    /** Retain neutral metadata and inspection contracts, never generic privileged persistence. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipReadiness $readiness, private OwnershipConstraintInspector $constraints) {}

    /** Require live source invariants for every financial and provider correlation boundary. */
    public function assertReady(): void
    {
        if ($this->resources->get('payments.attempts')->ownership->strategy === 'unscoped') {
            return;
        }
        foreach (['payments.attempts', 'payments.operations', 'payments.refunds', 'payments.webhooks'] as $key) {
            $this->readiness->assertActive($key);
        }
        foreach (self::Unique as $key => $sets) {
            $model = new ($this->resources->get($key)->model);
            $this->constraints->assertUniqueIndexes($model->getConnection(), $model->getTable(), $sets);
        }
        foreach (self::Assigned as $key => $fields) {
            $model = new ($this->resources->get($key)->model);
            $this->constraints->assertPresent($model->getConnection(), $model->getTable(), 'update', 'assigned:'.hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)));
        }
        $attempt = PaymentsModelRegistry::newAttempt();
        $purpose = 'assigned:'.hash('sha256', json_encode(['stripe_charge_id'], JSON_THROW_ON_ERROR)).':'.hash('sha256', json_encode(['state', self::SettledChargeStates], JSON_THROW_ON_ERROR));
        $this->constraints->assertPresent($attempt->getConnection(), $attempt->getTable(), 'update', $purpose);
        $operation = new PaymentOperation;
        $refund = PaymentsModelRegistry::newRefund();
        foreach ([[$operation, 'payment_attempt_id', $attempt], [$refund, 'payment_operation_id', $operation]] as [$child, $foreign, $parent]) {
            foreach (['insert', 'update'] as $event) {
                $this->constraints->assertPresent($child->getConnection(), $child->getTable(), $event, 'reference:'.$foreign.':'.$parent->getTable().':id');
            }
        }
    }
}
