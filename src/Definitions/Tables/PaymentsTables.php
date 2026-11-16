<?php

declare(strict_types=1);

namespace Nvl\Payments\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/** Names the tables owned by Payments. @internal */
final class PaymentsTables
{
    public const string Attempts = 'nvl_payments_attempts';

    public const string Operations = 'nvl_payments_operations';

    public const string Refunds = 'nvl_payments_refunds';

    public const string WebhookEvents = 'nvl_payments_webhook_events';

    public const string LedgerFacts = 'nvl_payments_ledger_facts';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return match ($key) {
            self::LedgerFacts => PackageStorage::table('payments', 'ledger_facts', self::LedgerFacts),
            default => PackageStorage::resolveTable('payments', $key),
        };
    }
}
