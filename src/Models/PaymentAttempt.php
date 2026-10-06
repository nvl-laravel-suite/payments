<?php

declare(strict_types=1);

namespace Nvl\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Payments\Database\Factories\PaymentAttemptFactory;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Models\Concerns\UsesPaymentsConnection;
use Nvl\Support\Config\PackageStorage;

/**
 * Represents one historical attempt to pay a host-owned order.
 *
 * @property string $id
 * @property string $order_reference
 * @property string $order_revision
 * @property int $amount_minor
 * @property string $currency
 * @property string $origin
 * @property string $state
 * @property string|null $reservation_key
 * @property string|null $checkout_url
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_charge_id
 * @property string|null $stripe_account_id
 * @property bool|null $stripe_livemode
 * @property string|null $capture_method
 * @property int $captured_amount_minor
 * @property int $refunded_amount_minor
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_reconcile_attempt_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory;

    use HasUuids;
    use UsesPaymentsConnection;

    public const string TABLE = PaymentsTables::Attempts;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'order_reference', 'order_revision', 'amount_minor', 'currency', 'origin', 'state',
        'reservation_key', 'checkout_url', 'stripe_checkout_session_id', 'stripe_payment_intent_id',
        'stripe_charge_id', 'stripe_account_id', 'stripe_livemode', 'capture_method',
        'captured_amount_minor', 'refunded_amount_minor', 'expires_at', 'last_synced_at', 'last_reconcile_attempt_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'captured_amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'stripe_livemode' => 'boolean',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_reconcile_attempt_at' => 'datetime',
        ];
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return PaymentsTables::get(PaymentsTables::Attempts);
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
    protected static function newFactory(): PaymentAttemptFactory
    {
        return PaymentAttemptFactory::new();
    }
}
