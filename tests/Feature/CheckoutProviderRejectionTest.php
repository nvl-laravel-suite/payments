<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\UnknownApiErrorException;

require_once __DIR__.'/../PaymentsTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    config(['nvl-payments.allowed_currencies' => ['EUR'], 'nvl-payments.checkout.return_hosts' => ['shop.test'], 'nvl-payments.stripe.account_id' => 'acct_test']);
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $orders->shouldReceive('resolve')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1000, 'EUR', 'Order one', null, true));
    app()->instance(PaymentOrderProvider::class, $orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway = FakePaymentGateway::fake(app());
    $this->start = fn (): array => app(PaymentCheckout::class)->start('order-1', new GenericUser(['id' => 'admin-1']), 'https://shop.test/success', 'https://shop.test/cancel');
});

it('releases the order reservation when Stripe rejects the Checkout Session before creating it', function (ApiErrorException $rejection): void {
    $this->gateway->willThrow('createCheckout', $rejection);
    expect($this->start)->toThrow($rejection::class);

    $rejected = PaymentAttempt::sole();
    expect($rejected->state)->toBe('failed')->and($rejected->reservation_key)->toBeNull()->and($rejected->stripe_checkout_session_id)->toBeNull()
        ->and(PaymentOperation::sole()->status)->toBe('failed')->and(PaymentOperation::sole()->resolved_at)->not->toBeNull();

    $this->gateway->willReturn('createCheckout', ['sessionId' => 'cs_retry', 'url' => 'https://checkout.stripe.com/retry', 'expiresAt' => CarbonImmutable::now()->addHour()]);
    expect(($this->start)()['sessionId'])->toBe('cs_retry')
        ->and(PaymentAttempt::query()->count())->toBe(2)
        ->and(PaymentAttempt::query()->whereNotNull('reservation_key')->sole()->state)->toBe('open');
})->with([
    'invalid request' => [fn () => InvalidRequestException::factory('Amount must be at least €0.50 eur', 400, null, null, null, 'amount_too_small')],
    'rejected credential' => [fn () => AuthenticationException::factory('Invalid API Key provided', 401)],
    'restricted credential' => [fn () => PermissionException::factory('The provided key does not have the required permissions', 403)],
    'rate limited' => [fn () => RateLimitException::factory('Too many requests', 429)],
]);

it('keeps the reservation when the Checkout Session outcome is uncertain', function (ApiErrorException $failure): void {
    $this->gateway->willThrow('createCheckout', $failure);
    expect($this->start)->toThrow($failure::class);

    expect(PaymentAttempt::sole()->state)->toBe('reserved')->and(PaymentAttempt::sole()->reservation_key)->not->toBeNull()
        ->and(PaymentOperation::sole()->status)->toBe('unknown');
    expect($this->start)->toThrow(PaymentsException::class);
    expect(PaymentAttempt::query()->count())->toBe(1);
})->with([
    'connection failure' => [fn () => ApiConnectionException::factory('Could not connect to Stripe')],
    'server failure' => [fn () => UnknownApiErrorException::factory('Internal error', 500)],
    'idempotency key in use' => [fn () => InvalidRequestException::factory('A request with this key is in progress', 400, null, null, null, 'idempotency_key_in_use')],
]);
