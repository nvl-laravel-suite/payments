<?php

declare(strict_types=1);

namespace Nvl\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Payments\Database\Factories\PaymentWebhookEventFactory;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Models\Concerns\UsesPaymentsConnection;
use Nvl\Support\Config\PackageStorage;

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
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class PaymentWebhookEvent extends Model
{
    /** @use HasFactory<PaymentWebhookEventFactory> */
    use HasFactory;

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

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return PaymentsTables::get(PaymentsTables::WebhookEvents);
    }

    /** Resolve the package connection through shared infrastructure defaults. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('payments') ?? parent::getConnectionName());
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): PaymentWebhookEventFactory
    {
        return PaymentWebhookEventFactory::new();
    }
}
