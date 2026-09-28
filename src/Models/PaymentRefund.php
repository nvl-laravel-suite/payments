<?php

declare(strict_types=1);

namespace Nvl\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Models\Concerns\UsesPaymentsConnection;

/**
 * Stores one requested or confirmed refund against an order payment.
 *
 * @property string $id
 * @property string $payment_attempt_id
 * @property string $payment_operation_id
 * @property string|null $stripe_refund_id
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string $status
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PaymentRefund extends Model
{
    use HasUuids;
    use UsesPaymentsConnection;

    public const string TABLE = PaymentsTables::Refunds;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'payment_attempt_id', 'payment_operation_id', 'stripe_refund_id',
        'amount_minor', 'currency', 'reason', 'internal_note', 'status', 'last_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'last_synced_at' => 'datetime'];
    }
}
