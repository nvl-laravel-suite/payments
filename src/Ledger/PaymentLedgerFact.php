<?php

declare(strict_types=1);

namespace Nvl\Payments\Ledger;

/**
 * Immutable, version 1 host ledger delivery with a stable idempotency key.
 *
 * @api
 */
interface PaymentLedgerFact
{
    public function id(): string;
}
