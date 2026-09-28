<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Nvl\Payments\Services\StripePaymentGateway;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

beforeEach(function (): void {
    $this->transport = new class implements ClientInterface
    {
        public array $responses = [];

        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->requests[] = compact('method', 'absUrl', 'headers', 'params');
            if (str_ends_with($absUrl, '/v1/account')) {
                return [json_encode(['id' => 'acct_expected', 'object' => 'account']), 200, []];
            }
            $response = array_shift($this->responses) ?? throw new RuntimeException('Unexpected Stripe request: '.$absUrl);

            return [json_encode($response), 200, []];
        }
    };
    ApiRequestor::setHttpClient($this->transport);
    $this->gateway = new StripePaymentGateway(new StripeClient('sk_test_fake'), 'acct_expected', false);
    $this->charge = ['id' => 'ch_one', 'object' => 'charge', 'livemode' => false, 'amount' => 1200, 'amount_captured' => 1000, 'amount_refunded' => 200, 'currency' => 'eur', 'status' => 'succeeded', 'captured' => true, 'payment_intent' => 'pi_one'];
    $this->intent = ['id' => 'pi_one', 'object' => 'payment_intent', 'livemode' => false, 'amount' => 1200, 'amount_received' => 1000, 'currency' => 'eur', 'status' => 'succeeded', 'capture_method' => 'manual', 'latest_charge' => $this->charge];
    $this->session = ['id' => 'cs_one', 'object' => 'checkout.session', 'livemode' => false, 'mode' => 'payment', 'url' => 'https://checkout.stripe.com/test', 'expires_at' => 1800000000, 'payment_intent' => 'pi_one', 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 1200, 'currency' => 'eur', 'metadata' => ['order_reference' => 'order-1', 'order_revision' => 'rev-2']];
    $this->refund = ['id' => 're_one', 'object' => 'refund', 'payment_intent' => 'pi_one', 'charge' => 'ch_one', 'amount' => 200, 'currency' => 'eur', 'status' => 'pending', 'reason' => 'requested_by_customer'];
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(new CurlClient);
});

it('creates a correlated one-time checkout from server minor units', function (string $capture): void {
    $this->transport->responses[] = $this->session;
    $result = $this->gateway->createCheckout(new OrderPaymentSnapshot('order-1', 'rev-2', 1200, 'EUR', 'Order 1', 'buyer@example.com', true), $capture, 'https://shop.test/success', 'https://shop.test/cancel', CarbonImmutable::createFromTimestampUTC(1800000000), 'checkout-key');
    $request = end($this->transport->requests);
    expect($result->sessionId)->toBe('cs_one')->and($result->expiresAt->timestamp)->toBe(1800000000)
        ->and($request['params']['mode'])->toBe('payment')
        ->and($request['params']['line_items'][0]['price_data']['unit_amount'])->toBe(1200)
        ->and($request['params']['line_items'][0]['price_data']['currency'])->toBe('eur')
        ->and($request['params']['payment_intent_data']['metadata'])->toBe($this->session['metadata'] + ['payment_operation_key' => 'checkout-key'])
        ->and($request['params']['metadata'])->toBe($this->session['metadata'] + ['payment_operation_key' => 'checkout-key'])
        ->and($request['params']['payment_intent_data']['capture_method'])->toBe($capture)
        ->and($request['headers'])->toContain('Idempotency-Key: checkout-key');
})->with(['automatic', 'manual']);

it('rejects unsupported manual capture methods before transport', function (): void {
    $gateway = new StripePaymentGateway(new StripeClient('sk_test_fake'), 'acct_expected', false, ['ideal']);
    expect(fn () => $gateway->createCheckout(new OrderPaymentSnapshot('o', 'r', 100, 'EUR', 'Order', null, true), 'manual', 'https://a.test', 'https://a.test', CarbonImmutable::now()->addHour(), 'key'))->toThrow(InvalidArgumentException::class);
    expect($this->transport->requests)->toBeEmpty();
});

it('normalizes payment references and preserves remote financial facts', function (string $reference): void {
    if (str_starts_with($reference, 'txn_')) {
        $this->transport->responses[] = ['id' => $reference, 'object' => 'balance_transaction', 'type' => 'charge', 'source' => 'ch_one'];
    }
    if ($reference !== 'pi_one') {
        $this->transport->responses[] = $this->charge;
    }
    $this->transport->responses[] = $this->intent;
    $state = $this->gateway->resolveExisting($reference);
    expect($state->paymentIntentId)->toBe('pi_one')->and($state->chargeId)->toBe('ch_one')->and($state->accountId)->toBe('acct_expected')->and($state->livemode)->toBeFalse()->and($state->currency)->toBe('eur')->and($state->status)->toBe('succeeded')->and($state->capturedAmountMinor)->toBe(1000)->and($state->refundedAmountMinor)->toBe(200);
})->with(['pi_one', 'ch_one', 'txn_one']);

it('rejects nonpayment balance transactions', function (): void {
    $this->transport->responses[] = ['id' => 'txn_one', 'object' => 'balance_transaction', 'type' => 'refund', 'source' => 're_one'];
    expect(fn () => $this->gateway->resolveExisting('txn_one'))->toThrow(InvalidArgumentException::class);
});

it('rejects mismatched account before reading a payment', function (): void {
    $gateway = new StripePaymentGateway(new StripeClient('sk_test_fake'), 'acct_other', false);
    expect(fn () => $gateway->payment('pi_one'))->toThrow(UnexpectedValueException::class);
    expect($this->transport->requests)->toHaveCount(1);
});

it('rejects remote mode mismatches', function (): void {
    $this->transport->responses[] = array_replace($this->intent, ['livemode' => true]);
    expect(fn () => $this->gateway->payment('pi_one'))->toThrow(UnexpectedValueException::class);
});

it('retrieves checkout state and expires with an explicit key', function (): void {
    $this->transport->responses = [$this->session, $this->session, $this->session];
    $state = $this->gateway->checkout('cs_one');
    expect($state->sessionId)->toBe('cs_one')->and($state->paymentIntentId)->toBe('pi_one')->and($state->orderReference)->toBe('order-1')->and($state->status)->toBe('open')->and($state->currency)->toBe('eur')->and($state->accountId)->toBe('acct_expected');
    $this->gateway->expireCheckout('cs_one', 'expire-key');
    expect(end($this->transport->requests)['headers'])->toContain('Idempotency-Key: expire-key');
});

it('captures and cancels with explicit keys', function (): void {
    $this->transport->responses = [$this->intent, $this->intent, $this->intent, $this->intent];
    expect($this->gateway->capture('pi_one', 1000, 'capture-key')->capturedAmountMinor)->toBe(1000);
    $request = end($this->transport->requests);
    expect($request['params']['amount_to_capture'])->toBe(1000)->and($request['params']['final_capture'])->toBe('true')->and($request['headers'])->toContain('Idempotency-Key: capture-key');
    $this->gateway->cancel('pi_one', 'cancel-key');
    expect(end($this->transport->requests)['headers'])->toContain('Idempotency-Key: cancel-key');
});

it('creates refunds against payment references and reads all pages', function (string $paymentId, string $field): void {
    $this->transport->responses = [...($paymentId === 'ch_one' ? [$this->charge, $this->intent] : [$this->intent]), $this->refund, ...($paymentId === 'ch_one' ? [$this->charge, $this->intent] : [$this->intent]), ['object' => 'list', 'url' => '/v1/refunds', 'data' => [$this->refund], 'has_more' => true], ['object' => 'list', 'url' => '/v1/refunds', 'data' => [array_replace($this->refund, ['id' => 're_two'])], 'has_more' => false]];
    $state = $this->gateway->refund($paymentId, 200, 'requested_by_customer', 'refund-key');
    $request = end($this->transport->requests);
    expect($state->refundId)->toBe('re_one')->and($state->status)->toBe('pending')->and($request['params'][$field])->toBe($paymentId)->and($request['headers'])->toContain('Idempotency-Key: refund-key');
    expect($this->gateway->refunds($paymentId))->toHaveCount(2);
    expect(end($this->transport->requests)['params']['starting_after'])->toBe('re_one');
})->with([['pi_one', 'payment_intent'], ['ch_one', 'charge']]);

it('reads standalone legacy charges without inventing a PaymentIntent', function (): void {
    $this->transport->responses[] = array_replace($this->charge, ['payment_intent' => null]);
    $state = $this->gateway->payment('ch_one');
    expect($state->paymentIntentId)->toBeNull()->and($state->capturedAmountMinor)->toBe(1000)->and($state->refundedAmountMinor)->toBe(200);
});

it('rejects mode mismatches before capturing', function (): void {
    $this->transport->responses[] = array_replace($this->intent, ['livemode' => true]);
    expect(fn () => $this->gateway->capture('pi_one', 100, 'key'))->toThrow(UnexpectedValueException::class);
    expect(array_column($this->transport->requests, 'method'))->not->toContain('post');
});

it('requires explicit nonblank mutation keys', function (): void {
    expect(fn () => $this->gateway->cancel('pi_one', ' '))->toThrow(InvalidArgumentException::class);
    expect($this->transport->requests)->toBeEmpty();
});

it('exposes authoritative authorization limits and card details', function (): void {
    $this->intent['amount_capturable'] = 1200;
    $this->intent['latest_charge']['payment_method_details'] = ['type' => 'card', 'card' => ['partial_authorization' => ['status' => 'fully_authorized']]];
    $this->transport->responses[] = $this->intent;
    $state = $this->gateway->payment('pi_one');
    expect($state->amountCapturableMinor)->toBe(1200)->and($state->paymentMethodType)->toBe('card')->and($state->partialAuthorizationStatus)->toBe('fully_authorized');
});

it('returns the Checkout recovery operation proof and hosted URL', function (): void {
    $this->session['metadata']['payment_operation_key'] = 'payments:checkout:original';
    $this->transport->responses[] = $this->session;
    $state = $this->gateway->checkout('cs_one');
    expect($state->operationKey)->toBe('payments:checkout:original')->and($state->url)->toBe('https://checkout.stripe.com/test');
});

it('writes and retrieves the persisted refund operation proof through Stripe metadata', function (): void {
    $this->refund['metadata'] = ['payment_operation_key' => 'refund-key'];
    $this->transport->responses = [$this->intent, $this->refund, $this->intent, ['object' => 'list', 'url' => '/v1/refunds', 'data' => [$this->refund], 'has_more' => false]];
    $result = $this->gateway->refund('pi_one', 200, 'requested_by_customer', 'refund-key');
    $request = end($this->transport->requests);
    expect($request['method'])->toBe('post')->and($request['absUrl'])->toEndWith('/v1/refunds')
        ->and($request['params']['metadata']['payment_operation_key'])->toBe('refund-key')->and($result->operationKey)->toBe('refund-key');
    expect($this->gateway->refunds('pi_one')[0]->operationKey)->toBe('refund-key');
});
