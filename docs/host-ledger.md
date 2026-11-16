# Host ledger contract v1

Bind `Nvl\Payments\Contracts\PaymentLedgerProjector`. Its `project()` method
receives an immutable `Nvl\Payments\Ledger\PaymentLedgerFact` after the outer
payments transaction commits. Classes under that namespace are a versioned
public contract. Monetary fields are integers in minor units and currencies
are uppercase ISO codes.

| Fact | Contents |
| --- | --- |
| `PaymentSucceeded` | Fact ID, host attempt and order references, captured amount and currency, intent and charge IDs, brand, last four, expiry month/year, wallet, mode and provider time when available. |
| `PaymentFailed`, `PaymentCanceled` | Original amount and currency, provider IDs, mode and time when available. Failure includes Stripe's failure code when available. |
| `PaymentExceptionRaised` | Observed and expected money, reason and provider IDs for investigation. |
| `RefundPending`, `RefundSucceeded`, `RefundFailed`, `RefundCanceled` | Provider refund and parent IDs, host operation UUID, amount, currency, host reason, provider status, mode and time when available. |

`providerOccurredAt` is a UTC Unix timestamp, nullable when the provider
supplies no corresponding timestamp. Matching payment webhooks supply the
Stripe event time. Cancellation can also use `canceled_at`. A re-fetch can
supply the provider object's creation time. Exact settlement time may be
unavailable on a read, particularly with delayed capture or refund action.

Delivery is **at least once**. Store the fact ID under a unique constraint and
write the receipt and domain changes in one host database transaction. A
failure must throw, so the package retains it for recovery. If the host
commits and the worker exits before acknowledgment, the same ID is delivered
again. `PaymentLedgerDelivery::deliver($id, replay: true)` explicitly replays
the stored fact. The saved contents and ID are identical. Ordinary delivery
preserves the order of undelivered facts within one attempt. Write host rows
transactionally and key payment and refund records by provider identity.
Keep external effects such as fulfillment in the host's own outbox.

```php
DB::transaction(function () use ($fact): void {
    $inserted = DB::table('payment_fact_receipts')->insertOrIgnore([
        'fact_id' => $fact->id(),
    ]);
    if ($inserted === 1) {
        // Upsert the relevant transaction or refund and update host totals.
    }
});
```

Persist refund transitions. Stripe can move a refund from succeeded back to
requires action and then fail it. Apply changes from the ordered fact stream
and keep succeeded money distinct from held refund reservations.

For a split-tender refund, allocate funds in the host's preferred order and
assign one stable operation UUID per allocation. For example, refund 500 minor
units to the host's gift-card ledger, then pass the remaining 700 to
`PaymentRefunds::refund($attemptOrChargeId, 700, 'return.damaged', null, $operationUuid, $actor)`.
The package checks the available balance for that exact payment, including
pending reservations and concurrent refund requests. Use the same UUID for
retries of an allocation. The host reason stays on the local record. Map
custom codes under `refund_reasons` when they have a Stripe equivalent:
`duplicate`, `fraudulent` or `requested_by_customer`.

Use `FakePaymentGateway` and `PaymentLedgerContractKit` against the actual host
binding. The kit checks idempotent effects, explicit replay against a stored
fact, and recovery after the binding throws. Use an isolated host test ledger.

Stripe references [refund creation and limits](https://docs.stripe.com/api/refunds/create),
[refund status and customer action](https://docs.stripe.com/refunds#refunds-that-require-action),
and the [charge object](https://docs.stripe.com/api/charges/object).
