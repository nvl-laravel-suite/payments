<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Exceptions\PaymentsException;
use Nvl\Payments\Exceptions\ProviderUnavailable;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Providers\PaymentsServiceProvider;
use Nvl\Payments\Services\PaymentLedgerDelivery;
use Nvl\Payments\Services\PaymentWebhookInbox;
use Nvl\Payments\Services\PaymentWebhooks;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;

uses(PaymentsSchemaTestCase::class);

function inboxEvent(array $changes = []): array
{
    return array_replace([
        'id' => 'evt_inbox', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'created' => 1820000123,
        'data' => ['object' => ['id' => 'pi_inbox', 'amount' => 1, 'currency' => 'usd', 'client_secret' => 'private_value']],
    ], $changes);
}

function inboxAttempt(): PaymentAttempt
{
    return PaymentAttempt::create([
        'order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR',
        'origin' => 'embedded', 'state' => 'open', 'capture_method' => 'automatic_async', 'stripe_payment_intent_id' => 'pi_inbox',
    ]);
}

function inboxPayment(): array
{
    return [
        'paymentIntentId' => 'pi_inbox', 'chargeId' => 'ch_inbox', 'accountId' => 'acct_inbox', 'livemode' => false,
        'amountMinor' => 1200, 'capturedAmountMinor' => 1200, 'refundedAmountMinor' => 0, 'currency' => 'eur',
        'status' => 'succeeded', 'captureMethod' => 'automatic_async', 'amountCapturableMinor' => 0,
        'paymentMethodType' => 'card', 'partialAuthorizationStatus' => null,
    ];
}

beforeEach(function (): void {
    config(['nvl-payments.stripe.account_id' => 'acct_inbox', 'nvl-payments.stripe.webhook_secret' => 'whsec_inbox']);
});

it('stores live and unsupported signed envelopes once without processing them', function (): void {
    $gateway = FakePaymentGateway::fake(app());
    $webhook = app(PaymentWebhooks::class);
    $event = inboxEvent(['livemode' => true]);
    $gateway->deliverWebhook($webhook, $event, 'whsec_inbox');
    $gateway->deliverWebhook($webhook, $event, 'whsec_inbox');
    $gateway->deliverWebhook($webhook, inboxEvent(['id' => 'evt_unsupported', 'type' => 'customer.updated']), 'whsec_inbox');
    expect(PaymentWebhookInbox::query()->orderBy('stripe_event_id')->pluck('outcome')->all())->toBe(['live_mode_disallowed', 'unsupported_event']);
    expect(PaymentWebhookInbox::query()->sum('attempts'))->toBe(0);
    $gateway->assertCalled('payment', times: 0);
});

it('acknowledges signed route intake before a real database queue worker reads authoritative facts', function (): void {
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config([
        'nvl-payments.webhooks.route' => true,
        'nvl-payments.webhooks.path' => 'stripe-payments-inbox',
        'nvl-payments.webhooks.connection' => 'database',
        'queue.connections.database.table' => 'jobs',
    ]);
    (new PaymentsServiceProvider(app()))->boot();
    try {
        $attempt = inboxAttempt();
        $gateway = FakePaymentGateway::fake(app())->willReturn('payment', inboxPayment());
        $path = '/stripe-payments-inbox';
        config(['nvl-payments.stripe.webhook_secret' => null]);
        $this->postJson($path)->assertNotFound();
        config(['nvl-payments.stripe.webhook_secret' => 'whsec_inbox']);
        $this->postJson($path)->assertBadRequest();
        $payload = json_encode(inboxEvent(), JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, 'whsec_inbox');
        $this->call('POST', $path, [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t=$time,v1=$signature"], $payload)->assertNoContent();
        expect($attempt->refresh()->state)->toBe('open')->and(PaymentWebhookInbox::query()->sole()->status)->toBe('pending')
            ->and(DB::table('jobs')->sole()->payload)->not->toContain('private_value');
        $gateway->assertCalled('payment', times: 0);
        $this->withoutMockingConsoleOutput();
        expect(Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'payments', '--once' => true, '--sleep' => 0, '--force' => true]))->toBe(0);
        expect($attempt->refresh()->state)->toBe('captured')
            ->and(PaymentWebhookInbox::query()->sole()->status)->toBe('processed')
            ->and(PaymentLedgerDelivery::query()->sole()->facts)->toContain('1820000123');
        $gateway->assertCalled('payment', times: 1);
    } finally {
        Schema::drop('jobs');
    }
});

it('caps failed and interrupted receipt attempts and prevents another claim while processing', function (): void {
    inboxAttempt();
    config(['nvl-payments.webhooks.max_attempts' => 2, 'nvl-payments.webhooks.stale_after_minutes' => 1]);
    $inbox = app(PaymentWebhookInbox::class);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('payment')->once()->andReturnUsing(function () use ($inbox): void {
        expect(PaymentWebhookInbox::query()->sole()->status)->toBe('processing');
        $inbox->handle('evt_inbox');
        throw new ProviderUnavailable;
    });
    app()->instance(PaymentGateway::class, $gateway);
    $payload = json_encode(inboxEvent(), JSON_THROW_ON_ERROR);
    $time = time();
    $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_inbox');
    expect(fn () => app(PaymentWebhooks::class)->process($payload, $signature))->toThrow(ProviderUnavailable::class);
    expect(PaymentWebhookInbox::query()->sole()->attempts)->toBe(1);
    PaymentWebhookInbox::query()->update(['status' => 'processing', 'updated_at' => now()->subMinutes(2)]);
    $gateway->shouldReceive('payment')->once()->andThrow(new ProviderUnavailable);
    expect($inbox->sweep(50))->toBe(1)->and($inbox->sweep(50))->toBe(0);
    expect(PaymentWebhookInbox::query()->sole()->attempts)->toBe(2);
});

it('rejects financial rollback without the explicit destructive flag', function (): void {
    config(['nvl-payments.migrations.allow_destructive_rollback' => false]);
    try {
        foreach (glob(__DIR__.'/../../database/migrations/payments/*.php') as $path) {
            $migration = require $path;
            expect(fn () => $migration->down())->toThrow(LogicException::class, 'allow_destructive_rollback');
        }
        expect(Schema::hasTable(PaymentsTables::Attempts))->toBeTrue();
    } finally {
        config(['nvl-payments.migrations.allow_destructive_rollback' => true]);
    }
});

it('recovers an expired claim only after a separate PostgreSQL session releases its lock', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Run on PostgreSQL to exercise distinct database sessions.');
    }

    $attempt = inboxAttempt();
    $eventId = 'evt_'.Str::random(12);
    $payload = json_encode(inboxEvent(['id' => $eventId]), JSON_THROW_ON_ERROR);
    $time = time();
    app(PaymentWebhooks::class)->enqueue($payload, 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_inbox'));
    PaymentWebhookInbox::query()->where('stripe_event_id', $eventId)->update(['status' => 'processing', 'attempts' => 1, 'updated_at' => now()->subHour()]);

    $default = config('database.default');
    config(['database.connections.provider_second_session' => config('database.connections.'.$default)]);
    $firstSession = DB::connection('provider_second_session');
    $key = hash('sha256', $firstSession->getDatabaseName().':'.PaymentsTables::get(PaymentsTables::WebhookInbox).':'.$eventId);
    $firstSession->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
    try {
        $gateway = FakePaymentGateway::fake(app())->willReturn('payment', inboxPayment());
        app(PaymentWebhookInbox::class)->handle($eventId);
        expect(PaymentWebhookInbox::query()->where('stripe_event_id', $eventId)->sole()->attempts)->toBe(1);
        $gateway->assertCalled('payment', times: 0);
    } finally {
        DB::purge('provider_second_session');
    }
    app(PaymentWebhookInbox::class)->handle($eventId);
    expect(PaymentWebhookInbox::query()->where('stripe_event_id', $eventId)->sole()->attempts)->toBe(2)
        ->and($attempt->refresh()->state)->toBe('captured');
});

it('re-fetches a signed refund by its verified identifier and projects the latest refund lifecycle', function (): void {
    $attempt = inboxAttempt();
    $attempt->update(['state' => 'captured', 'captured_amount_minor' => 1200, 'stripe_charge_id' => 'ch_inbox']);
    $refund = [
        'refundId' => 're_inbox', 'paymentIntentId' => 'pi_inbox', 'chargeId' => 'ch_inbox', 'accountId' => 'acct_inbox',
        'livemode' => false, 'amountMinor' => 100, 'currency' => 'eur', 'status' => 'succeeded', 'reason' => null, 'operationKey' => null,
    ];
    $gateway = FakePaymentGateway::fake(app())
        ->willReturn('readRefund', $refund)
        ->willReturn('payment', array_replace(inboxPayment(), ['refundedAmountMinor' => 100]))
        ->willReturn('refunds', [$refund]);
    $event = inboxEvent(['type' => 'refund.updated', 'data' => ['object' => ['id' => 're_inbox', 'amount' => 1, 'status' => 'failed']]]);
    expect(fn () => $gateway->deliverWebhook(app(PaymentWebhooks::class), $event))->toThrow(PaymentsException::class);
    expect(PaymentWebhookInbox::query()->count())->toBe(0);
    $gateway->deliverWebhook(app(PaymentWebhooks::class), $event, 'whsec_inbox');
    expect($attempt->refresh()->refunded_amount_minor)->toBe(100);
    expect(PaymentLedgerDelivery::query()->orderBy('sequence')->pluck('type')->all())->toBe(['payment_succeeded_v1', 'refund_succeeded_v1']);
    $gateway->assertCalled('readRefund', fn ($call) => $call->arguments === ['refundId' => 're_inbox']);
});
