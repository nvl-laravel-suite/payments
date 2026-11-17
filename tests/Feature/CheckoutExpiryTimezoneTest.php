<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Services\EmbeddedPayments;
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
    CarbonImmutable::setTestNow();
    date_default_timezone_set($this->timezone);
});

/** Read the stored expiry exactly as the database holds it. */
function storedCheckoutExpiry(): ?string
{
    $attempt = new PaymentAttempt;
    $value = DB::connection($attempt->getConnectionName())->table($attempt->getTable())->value('expires_at');

    return $value === null ? null : substr((string) $value, 0, 19);
}

/** Both occurrences of Sofia's repeated 03:30 wall clock on the 2026 daylight-saving fallback. */
dataset('sofia fallback expiries', [
    'first 03:30 (EEST)' => ['2026-10-25 00:30:00'],
    'second 03:30 (EET)' => ['2026-10-25 01:30:00'],
]);

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

it('keeps a hosted Checkout expiry inside the daylight-saving fallback hour', function (string $utc): void {
    $expiry = CarbonImmutable::parse($utc, 'UTC');
    CarbonImmutable::setTestNow($expiry->subHour());
    config(['nvl-payments.checkout.expires_in_minutes' => 60]);
    $this->gateway->willReturn('createCheckout', ['sessionId' => 'cs_fallback', 'url' => 'https://checkout.stripe.com/fallback', 'expiresAt' => $expiry]);

    app(PaymentCheckout::class)->start('order-1', $this->actor, 'https://shop.test/success', 'https://shop.test/cancel');

    expect($this->gateway->calls('createCheckout')[0]->arguments['expiresAt']->getTimestamp())->toBe($expiry->getTimestamp())
        ->and(storedCheckoutExpiry())->toBe($utc)
        ->and(PaymentAttempt::sole()->expires_at?->getTimestamp())->toBe($expiry->getTimestamp())
        ->and(app(PaymentReadService::class)->forOrder('order-1', $this->actor)['payments'][0]['expiresAt']?->getTimestamp())->toBe($expiry->getTimestamp());
})->with('sofia fallback expiries');

it('keeps a reconciled Checkout expiry inside the daylight-saving fallback hour', function (string $utc): void {
    $expiry = CarbonImmutable::parse($utc, 'UTC');
    CarbonImmutable::setTestNow($expiry->subHour());
    $attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'open', 'reservation_key' => 'order-1', 'stripe_checkout_session_id' => 'cs_fallback', 'capture_method' => 'automatic']);
    $this->gateway->willReturn('checkout', ['sessionId' => 'cs_fallback', 'paymentIntentId' => null, 'accountId' => 'acct_test', 'livemode' => false, 'amountMinor' => 1000, 'currency' => 'eur', 'status' => 'open', 'paymentStatus' => 'unpaid', 'orderReference' => 'order-1', 'orderRevision' => 'v1', 'expiresAt' => $expiry, 'operationKey' => null, 'url' => null]);

    app(PaymentReconciliation::class)->reconcile($attempt);

    expect(storedCheckoutExpiry())->toBe($utc)
        ->and($attempt->refresh()->expires_at?->getTimestamp())->toBe($expiry->getTimestamp());
})->with('sofia fallback expiries');

it('keeps an embedded payment window inside the daylight-saving fallback hour', function (string $utc): void {
    $expiry = CarbonImmutable::parse($utc, 'UTC');
    config(['nvl-payments.stripe.publishable_key' => 'pk_test_fallback', 'nvl-payments.embedded.expires_in_minutes' => 30]);
    CarbonImmutable::setTestNow($expiry->subMinutes(30));
    $attemptId = (string) Str::uuid();
    $facts = ['paymentIntentId' => 'pi_fallback', 'chargeId' => null, 'accountId' => 'acct_test', 'livemode' => false, 'amountMinor' => 1000, 'capturedAmountMinor' => 0, 'refundedAmountMinor' => 0, 'currency' => 'eur', 'status' => 'requires_payment_method', 'captureMethod' => 'automatic_async', 'amountCapturableMinor' => 0, 'paymentMethodType' => null, 'partialAuthorizationStatus' => null, 'clientSecret' => 'pi_fallback_secret_test', 'operationKey' => 'payments:embedded:'.$attemptId];
    $this->gateway->willReturn('createIntent', $facts);

    app(EmbeddedPayments::class)->start('order-1', $attemptId, $this->actor);

    expect(storedCheckoutExpiry())->toBe($utc)
        ->and(PaymentAttempt::sole()->expires_at?->getTimestamp())->toBe($expiry->getTimestamp());
})->with('sofia fallback expiries');
