<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Services\PaymentReadService;
use Nvl\Payments\Services\PaymentReconciliation;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

require_once __DIR__.'/../PaymentsTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    $this->timezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Sofia');
    config(['app.timezone' => 'Europe/Sofia', 'nvl-payments.allowed_currencies' => ['EUR'], 'nvl-payments.checkout.return_hosts' => ['shop.test'], 'nvl-payments.stripe.account_id' => 'acct_test']);
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $orders->shouldReceive('resolve')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1000, 'EUR', 'Order one', null, true));
    app()->instance(PaymentOrderProvider::class, $orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway = FakePaymentGateway::fake(app());
    $this->actor = new GenericUser(['id' => 'admin-1']);
});

afterEach(function (): void {
    date_default_timezone_set($this->timezone);
});

it('stores the provider Checkout expiry as the same instant when the application timezone is not UTC', function (): void {
    $expiry = CarbonImmutable::createFromTimestampUTC(CarbonImmutable::now()->addHours(2)->getTimestamp());
    $this->gateway->willReturn('createCheckout', ['sessionId' => 'cs_zone', 'url' => 'https://checkout.stripe.com/zone', 'expiresAt' => $expiry]);

    app(PaymentCheckout::class)->start('order-1', $this->actor, 'https://shop.test/success', 'https://shop.test/cancel');

    expect(PaymentAttempt::sole()->expires_at?->getTimestamp())->toBe($expiry->getTimestamp())
        ->and(app(PaymentReadService::class)->forOrder('order-1', $this->actor)['payments'][0]['expiresAt']?->getTimestamp())->toBe($expiry->getTimestamp());
});

it('stores a reconciled Checkout expiry as the same instant when the application timezone is not UTC', function (): void {
    $attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'open', 'reservation_key' => 'order-1', 'stripe_checkout_session_id' => 'cs_zone', 'capture_method' => 'automatic']);
    $expiry = CarbonImmutable::createFromTimestampUTC(CarbonImmutable::now()->addHours(3)->getTimestamp());
    $this->gateway->willReturn('checkout', ['sessionId' => 'cs_zone', 'paymentIntentId' => null, 'accountId' => 'acct_test', 'livemode' => false, 'amountMinor' => 1000, 'currency' => 'eur', 'status' => 'open', 'paymentStatus' => 'unpaid', 'orderReference' => 'order-1', 'orderRevision' => 'v1', 'expiresAt' => $expiry, 'operationKey' => null, 'url' => null]);

    app(PaymentReconciliation::class)->reconcile($attempt);

    expect($attempt->refresh()->expires_at?->getTimestamp())->toBe($expiry->getTimestamp());
});
