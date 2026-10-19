<?php

declare(strict_types=1);

use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

it('preserves legacy provider fingerprint bytes when native ownership metadata is attached', function (): void {
    $original = ['reference' => 'order-7', 'revision' => 'v1', 'amountMinor' => 1000, 'currency' => 'EUR', 'label' => 'Invoice', 'email' => null, 'payable' => true];
    $owner = new OwnerReference('company', 'native-store', 'int', '7');
    $snapshot = new OrderPaymentSnapshot(...[...$original, 'owner' => $owner]);
    expect(json_encode($snapshot, JSON_THROW_ON_ERROR))->toBe(json_encode($original, JSON_THROW_ON_ERROR))->and($snapshot->owner)->toBe($owner);
});
