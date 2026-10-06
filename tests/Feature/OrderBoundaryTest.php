<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Nvl\Payments\Contracts\ExistingPaymentOwnership;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Tests\PaymentsTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Support\Exceptions\BindingRequiredException;

uses(PaymentsTestCase::class);

function paymentOrderSnapshot(array $changes = []): OrderPaymentSnapshot
{
    return new OrderPaymentSnapshot(...array_replace([
        'reference' => 'order-123',
        'revision' => 'rev-1',
        'amountMinor' => 1299,
        'currency' => 'JPY',
        'label' => 'Order 123',
        'email' => 'buyer@example.test',
        'payable' => true,
    ], $changes));
}

it('denies order resolution and management without host bindings', function (): void {
    $order = paymentOrderSnapshot();
    $actor = new GenericUser(['id' => 'admin-1']);

    expect(fn () => app(PaymentOrderProvider::class)->resolve($order->reference))
        ->toThrow(BindingRequiredException::class);
    expect(fn () => app(PaymentManagementAccess::class)->assertCanManage($actor, 'refund', $order))
        ->toThrow(BindingRequiredException::class);
});

it('denies imported payment ownership without a host binding', function (): void {
    $payment = new StripePaymentState(
        paymentIntentId: 'pi_123', chargeId: 'ch_123', accountId: 'acct_123', livemode: false,
        amountMinor: 1299, capturedAmountMinor: 1299, refundedAmountMinor: 0,
        currency: 'JPY', status: 'succeeded', captureMethod: 'automatic',
    );

    expect(fn () => app(ExistingPaymentOwnership::class)->assertOwned(paymentOrderSnapshot(), $payment))
        ->toThrow(BindingRequiredException::class);
});

it('keeps server minor units unchanged and allows nonpayable snapshots for reads', function (): void {
    $order = paymentOrderSnapshot(['payable' => false]);

    expect($order->amountMinor)->toBe(1299)
        ->and($order->currency)->toBe('JPY')
        ->and($order->payable)->toBeFalse();
});

it('rejects malformed order snapshots', function (array $changes): void {
    expect(fn () => paymentOrderSnapshot($changes))->toThrow(InvalidArgumentException::class);
})->with([
    'blank reference' => [['reference' => '  ']],
    'blank revision' => [['revision' => '  ']],
    'zero amount' => [['amountMinor' => 0]],
    'negative amount' => [['amountMinor' => -1]],
    'short currency' => [['currency' => 'US']],
    'long currency' => [['currency' => 'USDD']],
    'numeric currency' => [['currency' => 'U1D']],
    'invalid email' => [['email' => 'not-an-email']],
]);
