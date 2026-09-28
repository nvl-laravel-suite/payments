<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Events\PaymentStateChanged;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentWebhookEvent;
use Nvl\Payments\Services\PaymentStateSyncer;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Nvl\Payments\ValueObjects\StripePaymentState;

require_once __DIR__.'/../PaymentsTestCase.php';
uses(PaymentsSchemaTestCase::class);

/** @param array<string, mixed> $attributes */
function webhookAttempt(array $attributes = []): PaymentAttempt
{
    return PaymentAttempt::create(array_replace(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR', 'origin' => 'checkout', 'state' => 'open', 'reservation_key' => 'order-1', 'stripe_checkout_session_id' => 'cs_one', 'stripe_payment_intent_id' => 'pi_one', 'stripe_account_id' => 'acct_one', 'stripe_livemode' => false, 'capture_method' => 'automatic'], $attributes));
}

/** @param array<string, mixed> $attributes */
function webhookPayment(array $attributes = []): StripePaymentState
{
    return new StripePaymentState(...array_replace(['paymentIntentId' => 'pi_one', 'chargeId' => 'ch_one', 'accountId' => 'acct_one', 'livemode' => false, 'amountMinor' => 1000, 'capturedAmountMinor' => 1000, 'refundedAmountMinor' => 0, 'currency' => 'eur', 'status' => 'succeeded', 'captureMethod' => 'automatic'], $attributes));
}

/** @param array<string, mixed> $attributes */
function webhookPayload(array $attributes = []): string
{
    return json_encode(array_replace(['id' => 'evt_one', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'data' => ['object' => ['id' => 'pi_one', 'object' => 'payment_intent']]], $attributes), JSON_THROW_ON_ERROR);
}

function deliverWebhook(string $payload, ?int $timestamp = null, string $secret = 'whsec_payments'): TestResponse
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return test()->call('POST', '/nvl/payments/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"], $payload);
}

beforeEach(function (): void {
    config(['payments.stripe.webhook_secret' => 'whsec_payments', 'payments.stripe.account_id' => 'acct_one']);
    $this->gateway = Mockery::mock(PaymentGateway::class);
    app()->instance(PaymentGateway::class, $this->gateway);
});

it('rejects missing invalid stale and malformed signatures or bodies', function (): void {
    $this->postJson('/nvl/payments/stripe/webhook', [])->assertStatus(400);
    deliverWebhook(webhookPayload(), secret: 'billing_secret')->assertStatus(400);
    deliverWebhook(webhookPayload(), time() - 600)->assertStatus(400);
    deliverWebhook('{')->assertStatus(400);
    deliverWebhook('{}')->assertStatus(400);
    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('synchronizes authoritative state once for duplicate signed deliveries', function (): void {
    $attempt = webhookAttempt();
    Event::fake([PaymentStateChanged::class]);
    $this->gateway->shouldReceive('payment')->once()->with('pi_one')->andReturnUsing(function (): StripePaymentState {
        expect((new PaymentAttempt)->getConnection()->transactionLevel())->toBe(0);

        return webhookPayment();
    });
    deliverWebhook(webhookPayload())->assertOk();
    deliverWebhook(webhookPayload())->assertOk();
    expect($attempt->refresh()->state)->toBe('captured')->and($attempt->captured_amount_minor)->toBe(1000)
        ->and(PaymentWebhookEvent::sole()->status)->toBe('processed');
    Event::assertDispatchedTimes(PaymentStateChanged::class, 1);
    Event::assertDispatched(PaymentStateChanged::class, fn ($event) => $event->orderReference === 'order-1' && $event->attemptId === $attempt->id && $event->oldState === 'open' && $event->newState === 'captured');
});

it('ignores unknown payments without inserting attempts', function (): void {
    deliverWebhook(webhookPayload())->assertOk();
    expect(PaymentAttempt::count())->toBe(0)->and(PaymentWebhookEvent::sole()->status)->toBe('ignored');
});

it('rejects wrong envelope mode and account before fetching', function (array $attributes): void {
    $attempt = webhookAttempt();
    deliverWebhook(webhookPayload($attributes))->assertStatus(400);
    expect($attempt->refresh()->state)->toBe('open');
})->with([[['livemode' => true]], [['account' => 'acct_other']]]);

it('does not mutate attempts on mismatched authoritative facts', function (array $attributes): void {
    $attempt = webhookAttempt();
    $this->gateway->shouldReceive('payment')->once()->andReturn(webhookPayment($attributes));
    deliverWebhook(webhookPayload())->assertStatus(400);
    expect($attempt->refresh()->state)->toBe('open')->and($attempt->stripe_charge_id)->toBeNull();
})->with([[['accountId' => 'acct_other']], [['livemode' => true]], [['currency' => 'usd']], [['amountMinor' => 999]], [['paymentIntentId' => 'pi_other']], [['captureMethod' => 'manual']]]);

it('maps processing failure and manual authorization from fresh Stripe facts', function (string $status, string $state, string $capture): void {
    $attempt = webhookAttempt(['capture_method' => $capture]);
    $this->gateway->shouldReceive('payment')->once()->andReturn(webhookPayment(['status' => $status, 'capturedAmountMinor' => 0, 'captureMethod' => $capture]));
    deliverWebhook(webhookPayload(['type' => 'payment_intent.processing']))->assertOk();
    expect($attempt->refresh()->state)->toBe($state)->and($attempt->reservation_key)->not->toBeNull();
})->with([['processing', 'processing', 'automatic'], ['requires_payment_method', 'failed', 'automatic'], ['requires_capture', 'authorized', 'manual']]);

it('correlates async Checkout success and failure through a known Session', function (string $type, string $status, string $state, int $captured): void {
    $attempt = webhookAttempt(['stripe_payment_intent_id' => null]);
    $this->gateway->shouldReceive('checkout')->once()->with('cs_one')->andReturn(new StripeCheckoutState('cs_one', 'pi_one', 'acct_one', false, 1000, 'eur', 'complete', 'unpaid', 'order-1', 'v1', CarbonImmutable::now()));
    $this->gateway->shouldReceive('payment')->once()->with('pi_one')->andReturn(webhookPayment(['status' => $status, 'capturedAmountMinor' => $captured]));
    deliverWebhook(webhookPayload(['type' => $type, 'data' => ['object' => ['id' => 'cs_one', 'object' => 'checkout.session']]]))->assertOk();
    expect($attempt->refresh()->state)->toBe($state)->and($attempt->stripe_payment_intent_id)->toBe('pi_one');
})->with([['checkout.session.async_payment_succeeded', 'succeeded', 'captured', 1000], ['checkout.session.async_payment_failed', 'requires_payment_method', 'failed', 0]]);

it('uses fresh state when an older failure event arrives after success', function (): void {
    $attempt = webhookAttempt();
    $this->gateway->shouldReceive('payment')->twice()->andReturn(webhookPayment());
    deliverWebhook(webhookPayload())->assertOk();
    deliverWebhook(webhookPayload(['id' => 'evt_old', 'type' => 'payment_intent.payment_failed', 'created' => 1]))->assertOk();
    expect($attempt->refresh()->state)->toBe('captured');
});

it('allows retry after a transient gateway failure', function (): void {
    webhookAttempt();
    $this->gateway->shouldReceive('payment')->once()->andThrow(new RuntimeException('timeout'));
    $this->gateway->shouldReceive('payment')->once()->andReturn(webhookPayment());
    deliverWebhook(webhookPayload())->assertStatus(500);
    deliverWebhook(webhookPayload())->assertOk();
    expect(PaymentAttempt::sole()->state)->toBe('captured')->and(PaymentWebhookEvent::sole()->status)->toBe('processed');
});

it('dispatches only after the outer commit and discards rolled back notifications', function (): void {
    webhookAttempt();
    $events = [];
    Event::listen(PaymentStateChanged::class, function ($event) use (&$events): void {
        $events[] = $event;
    });
    $connection = (new PaymentAttempt)->getConnection();
    $connection->beginTransaction();
    app(PaymentStateSyncer::class)->sync(webhookPayment());
    expect($events)->toBe([]);
    $connection->rollBack();
    expect($events)->toBe([])->and(PaymentAttempt::sole()->state)->toBe('open');
    $connection->beginTransaction();
    app(PaymentStateSyncer::class)->sync(webhookPayment());
    expect($events)->toBe([]);
    $connection->commit();
    expect($events)->toHaveCount(1);
});

it('accepts a reserved Session whose account is not yet synchronized', function (): void {
    $attempt = webhookAttempt(['stripe_payment_intent_id' => null, 'stripe_account_id' => null, 'stripe_livemode' => null]);
    $this->gateway->shouldReceive('checkout')->once()->andReturn(new StripeCheckoutState('cs_one', 'pi_one', 'acct_one', false, 1000, 'eur', 'complete', 'paid', 'order-1', 'v1', CarbonImmutable::now()));
    $this->gateway->shouldReceive('payment')->once()->andReturn(webhookPayment());
    deliverWebhook(webhookPayload(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_one']]]))->assertOk();
    expect($attempt->refresh()->state)->toBe('captured');
});

it('rejects malformed signed event structures', function (array $attributes): void {
    deliverWebhook(webhookPayload($attributes))->assertStatus(400);
})->with([[['data' => 'invalid']], [['data' => ['object' => 'invalid']]], [['id' => ['evt_invalid']]], [['type' => []]], [['livemode' => 'false']]]);

it('cannot regress a settled snapshot when a concurrent read finishes late', function (): void {
    $attempt = webhookAttempt();
    app(PaymentStateSyncer::class)->sync(webhookPayment());
    app(PaymentStateSyncer::class)->sync(webhookPayment(['status' => 'processing', 'capturedAmountMinor' => 0]));
    expect($attempt->refresh()->state)->toBe('captured')->and($attempt->captured_amount_minor)->toBe(1000);
});

it('never links a PaymentIntent from a mismatched Session', function (string $field, mixed $value): void {
    $attempt = webhookAttempt(['stripe_payment_intent_id' => null]);
    $session = new StripeCheckoutState('cs_one', 'pi_one', 'acct_one', false, 1000, 'eur', 'complete', 'paid', 'order-1', 'v1', CarbonImmutable::now());
    $attributes = get_object_vars($session);
    $attributes[$field] = $value;
    $this->gateway->shouldReceive('checkout')->once()->andReturn(new StripeCheckoutState(...$attributes));
    $this->gateway->shouldReceive('payment')->once()->andReturn(webhookPayment());
    deliverWebhook(webhookPayload(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_one']]]))->assertStatus(400);
    expect($attempt->refresh()->stripe_payment_intent_id)->toBeNull()->and($attempt->state)->toBe('open');
})->with([['orderReference', 'other'], ['orderRevision', 'v2'], ['sessionId', 'cs_other'], ['accountId', 'acct_other'], ['livemode', true], ['currency', 'usd'], ['amountMinor', 999]]);

it('fails closed when the signing secret is missing or Payments is disabled', function (): void {
    config(['payments.stripe.webhook_secret' => null]);
    deliverWebhook(webhookPayload())->assertStatus(503);
    config(['payments.enabled' => false]);
    deliverWebhook(webhookPayload())->assertNotFound();
});

it('waits for the configured Payments connection rather than the default connection', function (): void {
    config(['database.connections.payments_secondary' => config('database.connections.sqlite'), 'payments.connection' => 'payments_secondary']);
    $migration = require __DIR__.'/../../database/migrations/payments/2026_09_28_000001_create_payments_tables.php';
    $migration->up();
    webhookAttempt();
    $events = [];
    Event::listen(PaymentStateChanged::class, function ($event) use (&$events): void {
        $events[] = $event;
    });
    $connection = (new PaymentAttempt)->getConnection();
    $connection->beginTransaction();
    app(PaymentStateSyncer::class)->sync(webhookPayment());
    expect($events)->toBe([]);
    $connection->commit();
    expect($events)->toHaveCount(1);
    $migration->down();
    config(['payments.connection' => null]);
});
