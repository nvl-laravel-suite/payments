<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Services\PaymentAuthorizations;
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Services\PaymentReconciliation;
use Nvl\Payments\Services\PaymentRefunds;
use Nvl\Payments\Testing\FakePaymentGateway;
use Nvl\Payments\Tests\PaymentsSchemaTestCase;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

require_once __DIR__.'/../PaymentsTestCase.php';
uses(PaymentsSchemaTestCase::class);

beforeEach(function (): void {
    config(['nvl-payments.stripe.account_id' => 'acct_test']);
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $orders->shouldReceive('resolve')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1200, 'EUR', 'Order', null, false));
    app()->instance(PaymentOrderProvider::class, $orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage');
    app()->instance(PaymentManagementAccess::class, $access);
    $this->gateway = FakePaymentGateway::fake(app());
    $this->actor = new GenericUser(['id' => 'admin']);
});

it('treats a malformed attempt identifier as not found before it reaches the UUID key', function (Closure $operation): void {
    $attemptQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$attemptQueries): void {
        $attemptQueries += (int) str_contains($query->sql, (new PaymentAttempt)->getTable());
    });

    expect(fn () => $operation($this->actor))->toThrow(ModelNotFoundException::class);
    expect($attemptQueries)->toBe(0);
})->with([
    'refund' => [fn ($actor) => app(PaymentRefunds::class)->refund('not-a-uuid', 100, 'duplicate', null, (string) Str::uuid(), $actor)],
    'capture' => [fn ($actor) => app(PaymentAuthorizations::class)->capture('not-a-uuid', 100, (string) Str::uuid(), $actor)],
    'cancel' => [fn ($actor) => app(PaymentAuthorizations::class)->cancel('not-a-uuid', (string) Str::uuid(), $actor)],
    'recover' => [fn ($actor) => app(PaymentCheckout::class)->recover('not-a-uuid', 'cs_test', $actor)],
    'reconcile' => [fn () => app(PaymentReconciliation::class)->reconcile('not-a-uuid')],
    'resolve exception' => [fn ($actor) => app(PaymentReconciliation::class)->resolveException('not-a-uuid', (string) Str::uuid(), $actor)],
]);

it('rejects a malformed exception-acceptance operation UUID before any operation lookup or provider read', function (): void {
    $attempt = PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'attached', 'state' => 'payment_exception', 'captured_amount_minor' => 1200, 'capture_method' => 'automatic', 'stripe_payment_intent_id' => 'pi_exception']);
    $operationQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$operationQueries): void {
        $operationQueries += (int) str_contains($query->sql, (new PaymentOperation)->getTable());
    });

    expect(fn () => app(PaymentReconciliation::class)->resolveException($attempt, 'not-a-uuid', $this->actor))->toThrow(InvalidArgumentException::class);
    expect($operationQueries)->toBe(0)->and($attempt->refresh()->state)->toBe('payment_exception');
    $this->gateway->assertCalled('payment', times: 0);
});
