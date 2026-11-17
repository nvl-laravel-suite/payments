<?php

declare(strict_types=1);

namespace Nvl\Payments\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Immutable server-calculated payment facts for a host-owned order.
 *
 * @api
 */
final readonly class OrderPaymentSnapshot implements JsonSerializable
{
    /** Characters the native `varchar(255)` order identity columns store exactly on every supported driver. */
    private const int MAX_IDENTITY_LENGTH = 255;

    /** Validate the order facts supplied by the host. */
    public function __construct(
        public string $reference,
        public string $revision,
        public int $amountMinor,
        public string $currency,
        public string $label,
        public ?string $email,
        public bool $payable,
        public ?OwnerReference $owner = null,
    ) {
        foreach ([$this->reference, $this->revision] as $identity) {
            // The /u modifier fails on invalid UTF-8 and counts code points for the length bound.
            if (preg_match('/\A[^\x00]{1,'.self::MAX_IDENTITY_LENGTH.'}\z/u', $identity) !== 1 || trim($identity) === '') {
                throw new InvalidArgumentException('Order reference and revision must be nonblank UTF-8 without NUL bytes and at most 255 characters.');
            }
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

    /** Preserve the original provider fingerprint shape independently from native admission metadata.
     * @return array{reference:string,revision:string,amountMinor:int,currency:string,label:string,email:?string,payable:bool}
     */
    public function jsonSerialize(): array
    {
        return ['reference' => $this->reference, 'revision' => $this->revision, 'amountMinor' => $this->amountMinor,
            'currency' => $this->currency, 'label' => $this->label, 'email' => $this->email, 'payable' => $this->payable];
    }
}
