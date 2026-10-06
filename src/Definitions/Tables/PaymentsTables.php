<?php

declare(strict_types=1);

namespace Nvl\Payments\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/** Names the four tables owned by the optional Payments package. */
final class PaymentsTables
{
    public const string Attempts = 'nvl_payments_attempts';

    public const string Operations = 'nvl_payments_operations';

    public const string Refunds = 'nvl_payments_refunds';

    public const string WebhookEvents = 'nvl_payments_webhook_events';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('payments', $key);
    }
}
