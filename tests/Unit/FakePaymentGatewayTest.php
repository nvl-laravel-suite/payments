<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\ValueObjects\HostedCheckout;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\StripeCheckoutState;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;
use Nvl\Support\Testing\FakeCall;
use Nvl\Support\Testing\UnscriptedFakeCall;

dataset('scripted payment gateway methods', function (): array {
    $expiry = CarbonImmutable::parse('2030-01-01T12:00:00+00:00');
    $order = new OrderPaymentSnapshot('host-order-42', 'revision-1', 1200, 'EUR', 'Order', null, true);
    $checkout = new HostedCheckout('cs_42', 'https://checkout.example.test/42', $expiry);
    $state = new StripeCheckoutState('cs_42', 'pi_42', 'acct_42', false, 1200, 'EUR', 'complete', 'paid', 'host-order-42', 'revision-1', $expiry);
    $payment = new StripePaymentState('pi_42', 'ch_42', 'acct_42', false, 1200, 1200, 0, 'EUR', 'succeeded', 'automatic');
    $refund = new StripeRefundState('re_42', 'pi_42', 'ch_42', 'acct_42', false, 400, 'EUR', 'succeeded', null);

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

    expect(fn (): StripePaymentState => $first->payment('pi_42'))->toThrow(TypeError::class)
        ->and($executed)->toBeFalse()
        ->and(fn (): StripePaymentState => (new FakePaymentGateway)->payment('pi_42'))->toThrow(UnscriptedFakeCall::class);
});
