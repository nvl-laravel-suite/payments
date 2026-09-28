<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

use InvalidArgumentException;

/** Immutable server-calculated payment facts for a host-owned order. */
final readonly class OrderPaymentSnapshot
{
    /** Validate the order facts supplied by the host. */
    public function __construct(
        public string $reference,
        public string $revision,
        public int $amountMinor,
        public string $currency,
        public string $label,
        public ?string $email,
        public bool $payable,
    ) {
        if (trim($this->reference) === '' || trim($this->revision) === '') {
            throw new InvalidArgumentException('Order reference and revision must be nonblank.');
        }

        if ($this->amountMinor <= 0) {
            throw new InvalidArgumentException('Order amount must be positive minor units.');
        }

        if (preg_match('/^[A-Za-z]{3}$/D', $this->currency) !== 1) {
            throw new InvalidArgumentException('Order currency must be a three-letter ISO code.');
        }

        if ($this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Order email must be valid when supplied.');
        }
    }
}
