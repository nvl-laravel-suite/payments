<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentOperation;

/**
 * Owns the reusable durable operation boundary, including its atomic transitions.
 * Transactions here are intentional: all financial actions share this recovery journal.
 */
final class PaymentOperationJournal
{
    /** Reserve a UUID once, binding all supplied inputs to its immutable identity. */
    public function reserve(string $kind, string $subjectKey, string $operationId, string $actorId, string $fingerprint, ?int $amountMinor): PaymentOperation
    {
        if (! Str::isUuid($operationId) || trim($kind) === '' || trim($subjectKey) === '' || trim($actorId) === '' || trim($fingerprint) === '' || ($amountMinor !== null && $amountMinor <= 0)) {
            throw new InvalidArgumentException('Operation identity and inputs must be valid.');
        }

        $canonical = hash('sha256', json_encode([$kind, $subjectKey, $actorId, $fingerprint, $amountMinor], JSON_THROW_ON_ERROR));
        $operation = PaymentOperation::query()->createOrFirst(['id' => $operationId], [
            'order_reference' => $subjectKey,
            'type' => $kind,
            'actor_reference' => $actorId,
            'idempotency_key' => 'payments:'.$kind.':'.$operationId,
            'input_fingerprint' => $canonical,
            'status' => 'reserved',
        ]);

        if (! hash_equals($operation->input_fingerprint, $canonical)) {
            throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Operation UUID was already reserved with different inputs.');
        }

        return $operation;
    }

    /** Persist a confirmed remote result without allowing conflicting completion. */
    public function complete(PaymentOperation $operation, string $resultReference, ?string $requestReference = null): PaymentOperation
    {
        if (trim($resultReference) === '') {
            throw new InvalidArgumentException('A confirmed result reference is required.');
        }

        return $operation->getConnection()->transaction(function () use ($operation, $resultReference, $requestReference): PaymentOperation {
            $current = PaymentOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($current->status === 'completed') {
                if ($current->stripe_result_reference !== $resultReference) {
                    throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Operation already completed with another result.');
                }

                return $current;
            }
            if (! in_array($current->status, ['reserved', 'unknown'], true)) {
                throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Operation cannot be completed from its current status.');
            }
            $current->update(['status' => 'completed', 'stripe_result_reference' => $resultReference, 'stripe_request_reference' => $requestReference, 'resolved_at' => now()]);

            return $current;
        }, 5);
    }

    /** Preserve an uncertain outcome for reconciliation without regressing completion. */
    public function markUnknown(PaymentOperation $operation): PaymentOperation
    {
        PaymentOperation::query()->whereKey($operation->id)->where('status', 'reserved')->update(['status' => 'unknown']);

        return $operation->refresh();
    }
}
