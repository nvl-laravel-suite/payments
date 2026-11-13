<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Services\PaymentAuthorizations;
use Nvl\Payments\Services\PaymentReadService;
use Nvl\Payments\Services\PaymentReconciliation;
use Nvl\Payments\Services\PaymentRefunds;
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
 * Sanitized sandbox snapshots for a card authorized for 12.00 EUR.
 *
 * @return array<string, mixed>
 */
function releasedAuthorizationFixtures(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/stripe-released-authorization.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function releasedAuthorizationIntent(string $scenario = 'authorized'): array
{
    return releasedAuthorizationFixtures()['payment_intents'][$scenario];
}

/** Put the legacy reversal on a later page so both requests must expand its balance transaction. */
function releasedAuthorizationRefundPages(bool $legacy): array
{
    [$genuine, $reversal] = releasedAuthorizationFixtures()['refunds'];
    $page = ['object' => 'list', 'url' => '/v1/refunds', 'data' => [$genuine], 'has_more' => $legacy];

    return $legacy ? [$page, array_replace($page, ['data' => [$reversal], 'has_more' => false])] : [$page];
}

beforeEach(function (): void {
    $this->transport = new class implements ClientInterface
    {
        public array $responses = [];

        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->requests[] = $params;
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

it('cancels an authorization without creating a refund', function (): void {
    $canceled = releasedAuthorizationIntent('canceled');
    $this->transport->responses = [releasedAuthorizationIntent(), releasedAuthorizationIntent(), $canceled];

    $attempt = app(PaymentAuthorizations::class)->cancel($this->attempt, (string) Str::uuid(), $this->actor);

    expect($attempt->state)->toBe('canceled')->and($attempt->reservation_key)->toBeNull()
        ->and($attempt->captured_amount_minor)->toBe(0)->and($attempt->refunded_amount_minor)->toBe(0)
        ->and(PaymentOperation::sole()->status)->toBe('completed');
});

it('reconciles an authorization that expired uncaptured instead of rejecting its released hold', function (): void {
    $expired = array_replace(releasedAuthorizationIntent('canceled'), ['cancellation_reason' => 'expired']);
    $this->transport->responses = [$expired];

    $attempt = app(PaymentReconciliation::class)->reconcile($this->attempt);

    expect($attempt->state)->toBe('canceled')->and($attempt->reservation_key)->toBeNull()->and($attempt->refunded_amount_minor)->toBe(0);
});

it('partially captures an authorization without counting the released remainder as a refund', function (int $amount): void {
    $captured = releasedAuthorizationIntent('capture'.$amount);
    $this->transport->responses = [releasedAuthorizationIntent(), releasedAuthorizationIntent(), $captured];

    $attempt = app(PaymentAuthorizations::class)->capture($this->attempt, $amount, (string) Str::uuid(), $this->actor);

    expect($attempt->state)->toBe('captured')->and($attempt->captured_amount_minor)->toBe($amount)->and($attempt->refunded_amount_minor)->toBe(0)
        ->and(PaymentOperation::sole()->status)->toBe('completed');
})->with([700, 400]);

it('reports captured-money refunds after a partial capture', function (bool $legacy): void {
    $captured = releasedAuthorizationIntent($legacy ? 'legacy_refunded' : 'refunded');
    $pages = releasedAuthorizationRefundPages($legacy);
    $this->transport->responses = [$captured, ...$pages, $captured, ...$pages];
    $gateway = app(PaymentGateway::class);
    $state = $gateway->payment('pi_hold');

    expect($state['capturedAmountMinor'])->toBe(400)->and($state['refundedAmountMinor'])->toBe(200)
        ->and(array_column($gateway->refunds('pi_hold'), 'refundId'))->toBe(['re_actual']);
    if ($legacy) {
        expect(end($this->transport->requests))->toMatchArray(['starting_after' => 're_actual', 'expand' => ['data.balance_transaction']]);
    }
})->with(['current capture' => [false], 'legacy capture read using the current API' => [true]]);

it('reconciles a historical reversal without deleting a requested refund', function (bool $imported): void {
    $this->attempt->update(['state' => 'partially_refunded', 'captured_amount_minor' => 400, 'refunded_amount_minor' => 200]);
    $operation = PaymentOperation::factory()->forAttempt($this->attempt)->create([
        'type' => $imported ? 'reconcile_refund' : 'refund', 'status' => 'completed',
        'actor_reference' => $imported ? 'system:reconciliation' : 'admin', 'stripe_result_reference' => 're_release',
    ]);
    $oldRefund = PaymentRefund::query()->create([
        'payment_attempt_id' => $this->attempt->id, 'payment_operation_id' => $operation->id,
        'stripe_refund_id' => 're_release', 'amount_minor' => 800, 'currency' => 'EUR', 'status' => 'succeeded',
    ]);
    $captured = releasedAuthorizationIntent('legacy_refunded');
    $pages = releasedAuthorizationRefundPages(true);
    $read = [$captured, ...$pages, $captured, ...$pages];
    $this->transport->responses = [...$read, ...$read];

    if (! $imported) {
        expect(fn () => app(PaymentReconciliation::class)->reconcile($this->attempt))
            ->toThrow(PaymentsException::class, 'A partial capture reversal conflicts with a local refund operation.');
        expect($oldRefund->fresh())->not->toBeNull()->and(PaymentRefund::query()->count())->toBe(1);

        return;
    }

    app(PaymentReconciliation::class)->reconcile($this->attempt);
    app(PaymentReconciliation::class)->reconcile($this->attempt);

    $projection = app(PaymentReadService::class)->forOrder($this->attempt->order_reference, $this->actor);
    expect($oldRefund->fresh())->toBeNull()
        ->and($operation->refresh()->stripe_result_reference)->toBe('re_release')
        ->and(PaymentRefund::query()->sole()->stripe_refund_id)->toBe('re_actual')
        ->and($projection['payments'][0]['refundedAmountMinor'])->toBe(200)
        ->and($projection['payments'][0]['refundableAmountMinor'])->toBe(200);
})->with(['previous reconciliation import' => [true], 'requested refund' => [false]]);

it('refunds remaining captured money before reconciliation removes an imported partial-capture reversal', function (bool $imported): void {
    $this->attempt->update(['state' => 'partially_refunded', 'captured_amount_minor' => 400, 'refunded_amount_minor' => 200]);
    $operation = PaymentOperation::factory()->forAttempt($this->attempt)->create([
        'type' => $imported ? 'reconcile_refund' : 'refund', 'status' => 'completed',
        'actor_reference' => $imported ? 'system:reconciliation' : 'admin', 'stripe_result_reference' => 're_release',
    ]);
    $oldRefund = PaymentRefund::query()->create([
        'payment_attempt_id' => $this->attempt->id, 'payment_operation_id' => $operation->id,
        'stripe_refund_id' => 're_release', 'amount_minor' => 800, 'currency' => 'EUR', 'status' => 'succeeded',
    ]);
    $captured = releasedAuthorizationIntent('legacy_refunded');
    $pages = releasedAuthorizationRefundPages(true);
    $createdRefund = ['id' => 're_followup', 'object' => 'refund', 'amount' => 200, 'currency' => 'eur', 'reason' => 'requested_by_customer', 'status' => 'succeeded', 'payment_intent' => 'pi_hold', 'charge' => 'ch_hold'];
    $this->transport->responses = [$captured, ...$pages, $captured, ...$pages, $captured, ...$pages, $createdRefund];

    if (! $imported) {
        expect(fn () => app(PaymentRefunds::class)->refund($this->attempt, 200, 'requested_by_customer', null, (string) Str::uuid(), $this->actor))
            ->toThrow(PaymentsException::class, 'A partial capture reversal conflicts with a local refund operation.');
        expect($oldRefund->fresh())->not->toBeNull();

        return;
    }

    $refund = app(PaymentRefunds::class)->refund($this->attempt, 200, 'requested_by_customer', null, (string) Str::uuid(), $this->actor);

    expect($refund->stripe_refund_id)->toBe('re_followup')
        ->and($refund->status)->toBe('succeeded')
        ->and($oldRefund->fresh())->toBeNull();
})->with(['previous reconciliation import' => [true], 'requested refund' => [false]]);

it('ignores legacy full-reversal amount_refunded on uncaptured canceled charges and canceled reversal objects', function (): void {
    $preBasilCanceled = releasedAuthorizationIntent('canceled');
    $preBasilCanceled['latest_charge']['amount_refunded'] = 1200;
    $preBasilCanceled['latest_charge']['refunded'] = true;
    $this->transport->responses = [$preBasilCanceled];

    $attempt = app(PaymentReconciliation::class)->reconcile($this->attempt);
    expect($attempt->state)->toBe('canceled')->and($attempt->refunded_amount_minor)->toBe(0);

    [$genuine, $reversal] = releasedAuthorizationFixtures()['refunds'];
    $canceledReversal = array_replace($reversal, ['status' => 'canceled']);
    $captured = releasedAuthorizationIntent('refunded');
    $page = ['object' => 'list', 'url' => '/v1/refunds', 'data' => [$genuine, $canceledReversal], 'has_more' => false];
    $this->transport->responses = [$captured, $page];

    $state = app(PaymentGateway::class)->payment('pi_hold');
    expect($state['refundedAmountMinor'])->toBe(200)->and($state['releasedHoldRefundIds'])->toBe(['re_release']);
});
