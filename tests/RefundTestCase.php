<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Str;
use Nvl\Payments\Actions\RefundPaymentAction;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\Contracts\PaymentOrderProvider;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;
use Nvl\Payments\ValueObjects\RefundSnapshot;
use Nvl\Payments\ValueObjects\StripePaymentState;
use Nvl\Payments\ValueObjects\StripeRefundState;

require_once __DIR__.'/PaymentsTestCase.php';

function refundPayment(int $amount = 700, ?string $uuid = null, string $reason = 'requested_by_customer', ?string $note = null, ?string $attemptId = null): RefundSnapshot
{
    return app(RefundPaymentAction::class)->execute($attemptId ?? PaymentAttempt::sole()->id, new GenericUser(['id' => 'admin']), $amount, $reason, $note, $uuid ?? (string) Str::uuid());
}

function refundPaymentState(int $refunded = 0, int $captured = 1200): StripePaymentState
{
    return new StripePaymentState('pi_refund', 'ch_refund', 'acct_test', false, 1200, $captured, $refunded, 'eur', $captured === 0 ? 'requires_capture' : 'succeeded', 'automatic');
}

function remoteRefund(string $id = 're_test', int $amount = 700, string $status = 'succeeded'): StripeRefundState
{
    return new StripeRefundState($id, 'pi_refund', 'ch_refund', 'acct_test', false, $amount, 'eur', $status, 'requested_by_customer');
}

function setupRefundHost(): void
{
    config(['nvl-payments.stripe.account_id' => 'acct_test']);
    $orders = Mockery::mock(PaymentOrderProvider::class);
    $orders->shouldReceive('resolve')->with('order-1')->andReturn(new OrderPaymentSnapshot('order-1', 'v1', 1200, 'EUR', 'Order', null, false));
    app()->instance(PaymentOrderProvider::class, $orders);
    $access = Mockery::mock(PaymentManagementAccess::class);
    $access->shouldReceive('assertCanManage')->withArgs(fn ($actor, $kind, $order) => $kind === 'refund' && $order->reference === 'order-1');
    app()->instance(PaymentManagementAccess::class, $access);
}

function createRefundAttempt(): PaymentAttempt
{
    return PaymentAttempt::create(['order_reference' => 'order-1', 'order_revision' => 'v1', 'amount_minor' => 1200, 'currency' => 'EUR', 'origin' => 'attached', 'state' => 'captured', 'captured_amount_minor' => 1200, 'capture_method' => 'automatic', 'stripe_payment_intent_id' => 'pi_refund', 'stripe_charge_id' => 'ch_refund']);
}
