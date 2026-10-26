<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Support\Testing\FakeCall;
use Nvl\Support\Testing\UnscriptedFakeCall;

dataset('scripted payment gateway methods', function (): array {
    $expiry = CarbonImmutable::parse('2030-01-01T12:00:00+00:00');
    $order = new OrderPaymentSnapshot('host-order-42', 'revision-1', 1200, 'EUR', 'Order', null, true);
    $checkout = ['sessionId' => 'cs_42', 'url' => 'https://checkout.example.test/42', 'expiresAt' => $expiry];
    $state = ['sessionId' => 'cs_42', 'paymentIntentId' => 'pi_42', 'accountId' => 'acct_42', 'livemode' => false, 'amountMinor' => 1200, 'currency' => 'EUR', 'status' => 'complete', 'paymentStatus' => 'paid', 'orderReference' => 'host-order-42', 'orderRevision' => 'revision-1', 'expiresAt' => $expiry, 'operationKey' => null, 'url' => null];
    $payment = ['paymentIntentId' => 'pi_42', 'chargeId' => 'ch_42', 'accountId' => 'acct_42', 'livemode' => false, 'amountMinor' => 1200, 'capturedAmountMinor' => 1200, 'refundedAmountMinor' => 0, 'currency' => 'EUR', 'status' => 'succeeded', 'captureMethod' => 'automatic', 'amountCapturableMinor' => null, 'paymentMethodType' => null, 'partialAuthorizationStatus' => null];
    $refund = ['refundId' => 're_42', 'paymentIntentId' => 'pi_42', 'chargeId' => 'ch_42', 'accountId' => 'acct_42', 'livemode' => false, 'amountMinor' => 400, 'currency' => 'EUR', 'status' => 'succeeded', 'reason' => null, 'operationKey' => null];

    return [
        'checkout creation' => ['createCheckout', [$order, 'automatic', '/success', '/cancel', $expiry, 'operation-42'], $checkout],
        'checkout expiry' => ['expireCheckout', ['cs_42', 'operation-42'], null],
        'checkout read' => ['checkout', ['cs_42'], $state],
        'payment read' => ['payment', ['pi_42'], $payment],
        'existing payment read' => ['resolveExisting', ['pi_42'], $payment],
        'capture' => ['capture', ['pi_42', 1200, 'operation-42'], $payment],
        'cancel' => ['cancel', ['pi_42', 'operation-42'], $payment],
        'refund' => ['refund', ['pi_42', 400, 'requested_by_customer', 'operation-42'], $refund],
        'refund list' => ['refunds', ['pi_42'], [$refund]],
    ];
});

/** @param list<mixed> $arguments */
test('payment fake records native named arguments and consumes only explicit one time responses', function (string $method, array $arguments, mixed $result): void {
    $fake = (new FakePaymentGateway)->willReturn($method, $result);

    expect($fake->{$method}(...$arguments))->toBe($result)
        ->and(count($fake->calls($method)))->toBe(1)
        ->and(array_values($fake->calls($method)[0]->arguments))->toBe($arguments)
        ->and(fn (): mixed => $fake->{$method}(...$arguments))->toThrow(UnscriptedFakeCall::class);
    $fake->assertCalled($method, static fn (FakeCall $call): bool => array_values($call->arguments) === $arguments, times: 2);
})->with('scripted payment gateway methods');

/** @param list<mixed> $arguments */
test('payment fake propagates exact scripted gateway exceptions', function (string $method, array $arguments): void {
    $failure = new RuntimeException('gateway diagnostic');
    $fake = (new FakePaymentGateway)->willThrow($method, $failure);

    try {
        $fake->{$method}(...$arguments);
        test()->fail('The scripted gateway failure must propagate.');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }
    expect(array_values($fake->calls($method)[0]->arguments))->toBe($arguments);
})->with('scripted payment gateway methods');

test('refund fake rejects associative or incorrectly typed results without producing financial state', function (mixed $value): void {
    $fake = (new FakePaymentGateway)->willReturn('refunds', $value);

    expect(fn (): array => $fake->refunds('pi_42'))->toThrow(TypeError::class)
        ->and(count($fake->calls('refunds')))->toBe(1);
})->with([null, 'not-a-list', [['wrong' => new stdClass]], [[new stdClass]]]);

test('payment fake does not execute scripted closures or leak responses to another instance', function (): void {
    $executed = false;
    $first = (new FakePaymentGateway)->willReturn('payment', static function () use (&$executed): void {
        $executed = true;
    });

    expect(fn (): array => $first->payment('pi_42'))->toThrow(TypeError::class)
        ->and($executed)->toBeFalse()
        ->and(fn (): array => (new FakePaymentGateway)->payment('pi_42'))->toThrow(UnscriptedFakeCall::class);
});

test('payment fake rejects malformed native facts before returning them', function (): void {
    $fake = (new FakePaymentGateway)->willReturn('payment', ['paymentIntentId' => new stdClass]);

    expect(fn (): array => $fake->payment('pi_42'))->toThrow(TypeError::class);
});
