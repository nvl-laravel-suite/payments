<?php

declare(strict_types=1);

namespace Nvl\Payments\Ledger;

/**
 * Version 1 host ledger fact.
 *
 * @api
 */
readonly class PaymentSucceeded implements PaymentLedgerFact
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
        public ?string $cardBrand,
        public ?string $cardLastFour,
        public ?int $cardExpiryMonth,
        public ?int $cardExpiryYear,
        public ?string $wallet,
    ) {}

    public function id(): string
    {
        return $this->id;
    }
}
