<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;
use Throwable;

/**
 * Records versioned facts in the transaction that applies the provider evidence.
 *
 * @internal
 *
 * @phpstan-import-type StripePaymentState from \Nvl\Payments\Contracts\PaymentGateway
 * @phpstan-import-type StripeRefundState from \Nvl\Payments\Contracts\PaymentGateway
 */
final readonly class PaymentLedgerOutbox
{
    public function __construct(private PaymentLedgerDelivery $delivery) {}

    /** @param StripePaymentState $payment */
    public function payment(PaymentAttempt $attempt, array $payment, ?string $exceptionReason = null): void
    {
        $type = match (true) {
            $exceptionReason !== null => 'payment_exception_raised',
            $payment['status'] === 'succeeded' && $attempt->state !== 'payment_exception' => 'payment_succeeded',
            $attempt->state === 'canceled' => 'payment_canceled',
            $attempt->state === 'failed' => 'payment_failed',
            default => null,
        };
        if ($type === null) {
            return;
        }
        $extra = match ($type) {
            'payment_succeeded' => [
                'cardBrand' => $payment['cardBrand'] ?? null,
                'cardLastFour' => $payment['cardLastFour'] ?? null,
                'cardExpiryMonth' => $payment['cardExpiryMonth'] ?? null,
                'cardExpiryYear' => $payment['cardExpiryYear'] ?? null,
                'wallet' => $payment['wallet'] ?? null,
            ],
            'payment_failed' => ['failureCode' => $payment['failureCode'] ?? null],
            'payment_exception_raised' => ['reason' => $exceptionReason, 'expectedAmountMinor' => $attempt->amount_minor, 'expectedCurrency' => $attempt->currency],
            default => [],
        };
        $this->record($attempt->getConnection(), $attempt->id, $type,
            $type === 'payment_failed' ? ($payment['failureReference'] ?? $payment['chargeId'] ?? (string) ($payment['providerOccurredAt'] ?? '')) : '',
            [
                'attemptReference' => $attempt->id,
                'orderReference' => $attempt->order_reference,
                'amountMinor' => $payment['status'] === 'succeeded' ? $payment['capturedAmountMinor'] : $payment['amountMinor'],
                'currency' => strtoupper($payment['currency']),
                'intentId' => $payment['paymentIntentId'],
                'chargeId' => $payment['chargeId'],
                'livemode' => $payment['livemode'],
                'providerOccurredAt' => $payment['providerOccurredAt'] ?? null,
                ...$extra,
            ],
        );
    }

    /** @param array{amountMinor:int,currency:string,paymentIntentId:?string,livemode:bool} $checkout */
    public function checkoutException(PaymentAttempt $attempt, array $checkout, string $reason): void
    {
        $this->record($attempt->getConnection(), $attempt->id, 'payment_exception_raised', '', [
            'attemptReference' => $attempt->id,
            'orderReference' => $attempt->order_reference,
            'amountMinor' => $checkout['amountMinor'],
            'currency' => strtoupper($checkout['currency']),
            'intentId' => $checkout['paymentIntentId'],
            'chargeId' => $attempt->stripe_charge_id,
            'livemode' => $checkout['livemode'],
            'providerOccurredAt' => null,
            'reason' => $reason,
            'expectedAmountMinor' => $attempt->amount_minor,
            'expectedCurrency' => $attempt->currency,
        ]);
    }

    /** @param StripeRefundState $provider */
    public function refund(PaymentAttempt $attempt, PaymentRefund $refund, array $provider): void
    {
        $type = match ($refund->status) {
            'pending', 'requires_action' => 'refund_pending',
            'succeeded' => 'refund_succeeded',
            'failed' => 'refund_failed',
            'canceled' => 'refund_canceled',
            default => null,
        };
        if ($type === null) {
            return;
        }

        $this->record($attempt->getConnection(), $attempt->id, $type, $provider['refundId'].':'.((int) $refund->ledger_transition), [
            'attemptReference' => $attempt->id,
            'orderReference' => $attempt->order_reference,
            'amountMinor' => $refund->amount_minor,
            'currency' => $refund->currency,
            'intentId' => $provider['paymentIntentId'],
            'chargeId' => $provider['chargeId'],
            'livemode' => $provider['livemode'],
            'providerOccurredAt' => $provider['providerOccurredAt'] ?? null,
            'refundId' => $provider['refundId'],
            'operationId' => $refund->payment_operation_id,
            'reason' => $refund->reason,
            'providerStatus' => $refund->status,
        ]);
    }

    /** @param array<string, int|string|bool|null> $data */
    private function record(Connection $connection, string $attempt, string $type, string $reference, array $data): void
    {
        $type .= '_v1';
        $id = hash('sha256', json_encode([$attempt, $type, $reference], JSON_THROW_ON_ERROR));
        $inserted = PaymentLedgerDelivery::query()->insertOrIgnore([
            'id' => $id, 'attempt_id' => $attempt, 'type' => $type,
            'facts' => json_encode(['id' => $id, ...$data], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        if ($inserted === 1) {
            $connection->afterCommit(function () use ($id): void {
                try {
                    $this->delivery->deliver($id);
                } catch (Throwable $failure) {
                    Log::warning('payments.ledger_delivery_failed', ['fact_id' => $id, 'exception_type' => $failure::class]);
                }
            });
        }
    }
}
