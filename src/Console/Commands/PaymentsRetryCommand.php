<?php

declare(strict_types=1);

namespace Nvl\Payments\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Payments\Services\PaymentLedgerDelivery;

/** @internal */
final class PaymentsRetryCommand extends Command
{
    protected $signature = 'nvl:payments:retry {--limit=100}';

    protected $description = 'Retry host ledger delivery';

    public function handle(PaymentLedgerDelivery $ledger): int
    {
        $limit = (int) $this->option('limit');
        $this->info(sprintf('%d ledger facts delivered.', $ledger->retry($limit)));

        return self::SUCCESS;
    }
}
