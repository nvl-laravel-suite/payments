<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

require_once __DIR__.'/../PaymentsTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    config(['nvl-payments.allowed_currencies' => ['EUR'], 'nvl-payments.checkout.return_hosts' => ['shop.test'], 'nvl-payments.stripe.account_id' => 'acct_test']);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway = FakePaymentGateway::fake(app());
    $this->actor = new GenericUser(['id' => 'admin-1']);
});

/** Resolve host order facts from the requested reference and a fixed revision. */
function snapshotIdentityOrders(string $revision): void
{
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $orders->shouldReceive('resolve')->andReturnUsing(static fn (string $reference): OrderPaymentSnapshot => new OrderPaymentSnapshot($reference, $revision, 1000, 'EUR', 'Order', null, true));
    app()->instance(PaymentOrderProvider::class, $orders);
}

it('rejects order identities that persistence cannot store exactly before any effect', function (string $reference, string $revision): void {
    snapshotIdentityOrders($revision);

    expect(fn () => app(PaymentCheckout::class)->start($reference, $this->actor, 'https://shop.test/success', 'https://shop.test/cancel'))
        ->toThrow(InvalidArgumentException::class, 'Order reference and revision');
    expect(PaymentAttempt::query()->count())->toBe(0)
        ->and(PaymentOperation::query()->count())->toBe(0)
        ->and($this->gateway->calls('createCheckout'))->toBe([]);
})->with([
    'NUL reference' => ["order\0invalid", 'v1'],
    'NUL revision' => ['order-1', "v1\0invalid"],
    'invalid UTF-8 reference' => ["order-\xC3", 'v1'],
    'invalid UTF-8 revision' => ['order-1', "v1-\xC3"],
    'blank reference' => ['   ', 'v1'],
    'blank revision' => ['order-1', ' '],
    'oversized reference' => [str_repeat('r', 256), 'v1'],
    'oversized revision' => ['order-1', str_repeat('v', 256)],
]);

it('round-trips the longest accepted multibyte order identities exactly', function (): void {
    $reference = str_repeat('é', 255);
    $revision = str_repeat('ж', 255);
    snapshotIdentityOrders($revision);
    $this->gateway->willReturn('createCheckout', ['sessionId' => 'cs_identity', 'url' => 'https://checkout.stripe.com/identity', 'expiresAt' => CarbonImmutable::now()->addHour()]);

    app(PaymentCheckout::class)->start($reference, $this->actor, 'https://shop.test/success', 'https://shop.test/cancel');

    $attempt = PaymentAttempt::sole();
    expect($attempt->order_reference)->toBe($reference)
        ->and($attempt->order_revision)->toBe($revision)
        ->and($this->gateway->calls('createCheckout')[0]->arguments['order']->reference)->toBe($reference);
});
