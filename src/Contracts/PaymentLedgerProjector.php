<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

use Nvl\Payments\Ledger\PaymentLedgerFact;

/**
 * Mirror a version 1 fact in the host ledger after the outer Payments commit.
 * Throw to leave delivery pending. Delivery is at least once, keyed by fact ID.
 *
 * @api
 */
interface PaymentLedgerProjector
{
    public function project(PaymentLedgerFact $fact): void;
}
