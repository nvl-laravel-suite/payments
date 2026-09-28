<?php

declare(strict_types=1);

namespace Nvl\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Models\Concerns\UsesPaymentsConnection;

/**
 * Records one signed Stripe event and its processing outcome.
 *
 * @property string $id
 * @property string $stripe_event_id
 * @property string $type
 * @property string $status
 * @property string|null $stripe_object_id
 * @property string|null $stripe_account_id
 * @property bool $stripe_livemode
 * @property string|null $outcome
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PaymentWebhookEvent extends Model
{
    use HasUuids;
    use UsesPaymentsConnection;

    public const string TABLE = PaymentsTables::WebhookEvents;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'stripe_event_id', 'type', 'status', 'stripe_object_id',
        'stripe_account_id', 'stripe_livemode', 'outcome', 'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['stripe_livemode' => 'boolean', 'processed_at' => 'datetime'];
    }
}
