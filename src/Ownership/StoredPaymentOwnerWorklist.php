<?php

declare(strict_types=1);

namespace Nvl\Payments\Ownership;

use Nvl\Payments\Contracts\PaymentOwnerWorklist;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Projects only source owner identity; it cannot expose financial data or admit an operation.
 *
 * @internal
 */
final readonly class StoredPaymentOwnerWorklist implements PaymentOwnerWorklist
{
    /** Retain current-context lookup and live source integrity checks. */
    public function __construct(private OwnershipContextResolver $context, private PaymentsIntegrityGuard $integrity) {}

    /** @return iterable<OwnerReference> */
    public function owners(?string $attemptId, int $limit): iterable
    {
        $snapshot = $this->context->current()->snapshot();
        if ($snapshot->publicSite !== null || ! in_array($snapshot->mode, [OwnershipContextMode::Owner, OwnershipContextMode::Unresolved], true)
            || $limit < 1 || $limit > 10000) {
            throw new OwnershipViolation;
        }
        $this->integrity->assertReady();
        $model = new PaymentAttempt;
        $query = $model->getConnection()->table($model->getTable())->useWritePdo()->select(['id', 'owner_scope_key', 'owner_reference']);
        if ($attemptId !== null) {
            $query->where('id', $attemptId);
        }
        if ($snapshot->mode === OwnershipContextMode::Owner) {
            $query->where('owner_scope_key', $this->context->current()->requireOwner()->scopeKey());
        }
        $seen = [];
        foreach ($query->orderByRaw('CASE WHEN last_reconcile_attempt_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_reconcile_attempt_at')->orderBy('id')->limit($limit)->get() as $row) {
            if (! is_string($row->owner_reference)) {
                throw new OwnershipViolation;
            }
            $owner = OwnerReference::fromJson($row->owner_reference);
            if ($owner->scopeKey() !== $row->owner_scope_key) {
                throw new OwnershipViolation;
            }
            if (! isset($seen[$owner->scopeKey()])) {
                $seen[$owner->scopeKey()] = true;
                yield $owner;
            }
        }
    }
}
