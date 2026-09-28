<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;

require_once __DIR__.'/RefundTestCase.php';

function reconciliationOperation(PaymentAttempt $attempt, string $type, string $status = 'unknown'): PaymentOperation
{
    $id = (string) Str::uuid();

    return PaymentOperation::create(['id' => $id, 'payment_attempt_id' => $attempt->id, 'order_reference' => $attempt->order_reference, 'type' => $type, 'status' => $status, 'idempotency_key' => 'payments:'.$type.':'.$id, 'input_fingerprint' => 'test']);
}

function reconciliationRefund(PaymentAttempt $attempt, ?string $stripeId, string $status = 'reserved', int $amount = 700): PaymentRefund
{
    $operation = reconciliationOperation($attempt, 'refund');

    return PaymentRefund::create(['payment_attempt_id' => $attempt->id, 'payment_operation_id' => $operation->id, 'stripe_refund_id' => $stripeId, 'amount_minor' => $amount, 'currency' => 'EUR', 'reason' => 'requested_by_customer', 'status' => $status]);
}
