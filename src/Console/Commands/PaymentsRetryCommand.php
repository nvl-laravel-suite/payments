<?php

declare(strict_types=1);

namespace Nvl\Payments\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Payments\Services\PaymentLedgerDelivery;
use Nvl\Payments\Services\PaymentWebhookInbox;

/** @internal */
final class PaymentsRetryCommand extends Command
{
    protected $signature = 'nvl:payments:retry {--limit=100}';

    protected $description = 'Requeue recoverable Stripe receipts and retry host ledger delivery';

    public function handle(PaymentWebhookInbox $inbox, PaymentLedgerDelivery $ledger): int
    {
        $limit = (int) $this->option('limit');
        $this->info(sprintf('%d webhook receipts selected, %d ledger facts delivered.', $inbox->sweep($limit), $ledger->retry($limit)));

        return self::SUCCESS;
    }
}
