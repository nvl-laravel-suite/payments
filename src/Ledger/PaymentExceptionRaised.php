<?php

declare(strict_types=1);

namespace Nvl\Payments\Ledger;

/**
 * Version 1 host ledger fact.
 *
 * @api
 */
readonly class PaymentExceptionRaised implements PaymentLedgerFact
{
    public function __construct(
        public string $id,
        public string $attemptReference,
        public string $orderReference,
        public int $amountMinor,
        public string $currency,
        public ?string $intentId,
        public ?string $chargeId,
        public bool $livemode,
        public ?int $providerOccurredAt,
        public string $reason,
        public int $expectedAmountMinor,
        public string $expectedCurrency,
    ) {}

    public function id(): string
    {
        return $this->id;
    }
}
