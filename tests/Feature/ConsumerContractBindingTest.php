<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Payments\Providers\PaymentsServiceProvider;
use Nvl\Payments\Services\PaymentAuthorizations;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Services\PaymentReadService;
use Nvl\Payments\Services\PaymentReconciliation;
use Nvl\Payments\Services\PaymentRefunds;
use Nvl\Payments\Services\PaymentWebhooks;
use Nvl\Payments\Tests\PaymentsTestCase;

uses(PaymentsTestCase::class);

it('exposes complete native services without package Actions or payload carriers', function (): void {
    $operations = [
        PaymentCheckout::class => ['start' => 'array', 'recover' => PaymentAttempt::class, 'attach' => PaymentAttempt::class],
        PaymentAuthorizations::class => ['capture' => PaymentAttempt::class, 'cancel' => PaymentAttempt::class],
        PaymentRefunds::class => ['refund' => PaymentRefund::class],
        PaymentReconciliation::class => ['reconcile' => PaymentAttempt::class, 'resolveException' => PaymentAttempt::class],
        PaymentWebhooks::class => ['process' => 'void'],
        PaymentReadService::class => ['forOrder' => 'array'],
    ];
    foreach ($operations as $service => $methods) {
        $reflection = new ReflectionClass($service);
        expect($reflection->isFinal())->toBeTrue()->and($reflection->getDocComment())->toContain('@api');
        foreach ($methods as $method => $result) {
            $operation = $reflection->getMethod($method);
            expect($operation->isPublic())->toBeTrue()->and((string) $operation->getReturnType())->toBe($result)
                ->and($operation->getDocComment())->not->toBeFalse();
        }
    }
    expect(is_file(dirname(__DIR__, 2).'/src/Actions/RefundPaymentAction.php'))->toBeFalse()
        ->and(is_file(dirname(__DIR__, 2).'/src/Contracts/RefundPaymentContract.php'))->toBeFalse()
        ->and(is_file(dirname(__DIR__, 2).'/src/ValueObjects/HostedCheckout.php'))->toBeFalse()
        ->and(is_file(dirname(__DIR__, 2).'/src/ValueObjects/StripePaymentState.php'))->toBeFalse();
});

it('accepts exact refund minor units with the actor last and verifies raw webhook bytes', function (): void {
    $refund = new ReflectionMethod(PaymentRefunds::class, 'refund');
    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $refund->getParameters()))
        ->toBe(['attempt', 'amountMinor', 'reason', 'note', 'operationId', 'actor'])
        ->and((string) $refund->getParameters()[1]->getType())->toBe('int')
        ->and((string) $refund->getParameters()[5]->getType())->toBe(Authenticatable::class);
    $webhook = new ReflectionMethod(PaymentWebhooks::class, 'process');
    expect(array_map(fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), $webhook->getParameters()))->toBe(['string', 'string']);
});

it('leaves webhook transport registration to the consumer even when enabled', function (): void {
    config(['nvl-payments.enabled' => true]);
    (new PaymentsServiceProvider(app()))->boot();
    expect(Route::getRoutes()->getByName('nvl.payments.webhook'))->toBeNull();
});
