<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Nvl\Payments\Models\PaymentRefund;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;

/**
 * Persists refund reservations and reconciled facts through the canonical source payment assignment.
 *
 * @internal
 */
final readonly class PaymentRefundWriter
{
    /** Retain the neutral source writer without retaining any financial record. */
    public function __construct(private OwnershipWriter $writer) {}

    /** Create one refund whose payment and operation are constrained in the same native owner.
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes): PaymentRefund
    {
        return $this->writer->create(PaymentsModelRegistry::refundClass(), static function (array $ownership) use ($attributes): PaymentRefund {
            $refund = PaymentsModelRegistry::newRefund();
            $refund->forceFill([...$attributes, ...$ownership])->save();

            return $refund;
        });
    }
}
