<?php

declare(strict_types=1);

namespace Nvl\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Models\Concerns\UsesPaymentsConnection;

/**
 * Records one durable Stripe operation and its idempotency identity.
 *
 * @property string $id
 * @property string|null $payment_attempt_id
 * @property string $order_reference
 * @property string $type
 * @property string|null $actor_reference
 * @property string $idempotency_key
 * @property string $input_fingerprint
 * @property string|null $stripe_request_reference
 * @property string|null $stripe_result_reference
 * @property string $status
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PaymentOperation extends Model
{
    use HasUuids;
    use UsesPaymentsConnection;

    public const string TABLE = PaymentsTables::Operations;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'id', 'payment_attempt_id', 'order_reference', 'type', 'actor_reference',
        'idempotency_key', 'input_fingerprint', 'stripe_request_reference',
        'stripe_result_reference', 'status', 'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
