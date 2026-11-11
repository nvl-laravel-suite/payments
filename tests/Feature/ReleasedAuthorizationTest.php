<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\PaymentAuthorizations;
use Nvl\Payments\Services\PaymentReconciliation;
use Nvl\Payments\Services\StripePaymentGateway;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

require_once __DIR__.'/../PaymentsTestCase.php';
uses(PaymentsSchemaTestCase::class);

/**
 * Stripe reports a released authorization on the Charge as refunded: `amount_refunded` grows by the
 * uncaptured amount when an authorization is canceled, expires or is only partially captured.
 *
 * @param  array<string, mixed>  $intent
 * @param  array<string, mixed>  $charge
 * @return array<string, mixed>
 */
function releasedAuthorizationIntent(array $intent = [], array $charge = []): array
{
    return array_replace(['id' => 'pi_hold', 'object' => 'payment_intent', 'livemode' => false, 'amount' => 1200, 'amount_received' => 0, 'amount_capturable' => 1200, 'currency' => 'eur', 'status' => 'requires_capture', 'capture_method' => 'manual',
        'latest_charge' => array_replace(['id' => 'ch_hold', 'object' => 'charge', 'livemode' => false, 'amount' => 1200, 'amount_captured' => 0, 'amount_refunded' => 0, 'currency' => 'eur', 'status' => 'succeeded', 'captured' => false, 'refunded' => false, 'payment_intent' => 'pi_hold', 'payment_method_details' => ['type' => 'card', 'card' => []]], $charge)], $intent);
}

beforeEach(function (): void {
    $this->transport = new class implements ClientInterface
    {
        public array $responses = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            if (str_ends_with($absUrl, '/v1/account')) {
                return [json_encode(['id' => 'acct_expected', 'object' => 'account']), 200, []];
            }
            $response = array_shift($this->responses) ?? throw new RuntimeException('Unexpected Stripe request: '.$absUrl);

            return [json_encode($response), 200, []];
        }
    };
    ApiRequestor::setHttpClient($this->transport);
    config(['nvl-payments.stripe.account_id' => 'acct_expected']);
    app()->instance(PaymentGateway::class, new StripePaymentGateway(new StripeClient('sk_test_fake'), 'acct_expected', false));
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $orders->shouldReceive('resolve')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1200, 'EUR', 'Order', null, false));
    app()->instance(PaymentOrderProvider::class, $orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'authorized', 'capture_method' => 'manual', 'stripe_payment_intent_id' => 'pi_hold', 'stripe_charge_id' => 'ch_hold', 'reservation_key' => 'reserved-order']);
    $this->actor = new GenericUser(['id' => 'admin']);
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(new CurlClient);
});

it('cancels an authorization whose released hold Stripe reports as a refunded charge', function (): void {
    $canceled = releasedAuthorizationIntent(['status' => 'canceled', 'amount_capturable' => 0], ['amount_refunded' => 1200, 'refunded' => true]);
    $this->transport->responses = [releasedAuthorizationIntent(), releasedAuthorizationIntent(), $canceled];

    $attempt = app(PaymentAuthorizations::class)->cancel($this->attempt, (string) Str::uuid(), $this->actor);

    expect($attempt->state)->toBe('canceled')->and($attempt->reservation_key)->toBeNull()
        ->and($attempt->captured_amount_minor)->toBe(0)->and($attempt->refunded_amount_minor)->toBe(0)
        ->and(PaymentOperation::sole()->status)->toBe('completed');
});

it('reconciles an authorization that expired uncaptured instead of rejecting its released hold', function (): void {
    $this->transport->responses = [releasedAuthorizationIntent(['status' => 'canceled', 'amount_capturable' => 0], ['amount_refunded' => 1200, 'refunded' => true])];

    $attempt = app(PaymentReconciliation::class)->reconcile($this->attempt);

    expect($attempt->state)->toBe('canceled')->and($attempt->reservation_key)->toBeNull()->and($attempt->refunded_amount_minor)->toBe(0);
});

it('captures less than half of an authorization without counting the released remainder as a refund', function (): void {
    $captured = releasedAuthorizationIntent(['status' => 'succeeded', 'amount_received' => 500, 'amount_capturable' => 0], ['amount_captured' => 500, 'amount_refunded' => 700, 'captured' => true]);
    $this->transport->responses = [releasedAuthorizationIntent(), releasedAuthorizationIntent(), $captured];

    $attempt = app(PaymentAuthorizations::class)->capture($this->attempt, 500, (string) Str::uuid(), $this->actor);

    expect($attempt->state)->toBe('captured')->and($attempt->captured_amount_minor)->toBe(500)->and($attempt->refunded_amount_minor)->toBe(0)
        ->and(PaymentOperation::sole()->status)->toBe('completed');
});

it('still reports money refunded after a partial capture', function (): void {
    $this->transport->responses = [releasedAuthorizationIntent(['status' => 'succeeded', 'amount_received' => 500, 'amount_capturable' => 0], ['amount_captured' => 500, 'amount_refunded' => 900, 'captured' => true])];

    $state = app(PaymentGateway::class)->payment('pi_hold');

    expect($state['capturedAmountMinor'])->toBe(500)->and($state['refundedAmountMinor'])->toBe(200);
});
