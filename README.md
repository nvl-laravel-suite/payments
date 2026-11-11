# NVL Payments

Standalone Stripe order payments for PHP 8.4 and Laravel 13. Install Payments separately from the suite metapackage. It requires Core and Stripe PHP; Billing, Cashier, Tenancy and application DTO libraries are not required.

The host owns orders, prices, permissions, fulfillment and HTTP. Payments owns hosted checkout, authorization, refund reservations, signed delivery processing and durable operation journals.

## Setup

Publish `nvl-payments-config` and choose one migration source. Vendor migrations require `nvl-payments.migrations.enabled=true`; published migrations require that option to remain false. Keep `nvl-payments.enabled=false` until schema, Stripe configuration and host adapters are ready.

Configure the Stripe secret, account ID, live/test mode, Payments-specific webhook secret, allowed currencies and HTTPS checkout return hosts. Tables use the canonical `nvl_payments_*` defaults and `nvl-payments.connection` selects the configured connection.

## Required bindings

Bind these host integration contracts before resolving payment services:

- `PaymentOrderProvider::resolve($reference)` supplies a validated `OrderPaymentSnapshot` with server-calculated integer minor units, currency and revision.
- `PaymentManagementAccess::assertCanManage($actor, $operation, $order)` enforces host permissions. Operation names are `start_checkout`, `recover_checkout`, `attach_existing`, `capture`, `cancel_authorization`, `refund`, `resolve_payment_exception` and `view`.
- `ExistingPaymentOwnership::assertOwned($order, $payment)` verifies trusted evidence linking an imported Stripe payment to the host order. `$payment` is the documented native fact array in `PaymentGateway`.

Defaults deny until configured. Services reload canonical Eloquent actors before consulting host permissions; non-Eloquent identity freshness remains the host provider’s responsibility. `PaymentGateway` remains the replaceable Stripe integration boundary; provider discovery preserves host bindings.

## Native services

Inject focused services from `Nvl\Payments\Services`. Consumers may wrap them in their own Actions and data classes.

| Service | Operations | Results |
| --- | --- | --- |
| `PaymentCheckout` | `start`, `recover`, `attach` | Hosted checkout fact array; `PaymentAttempt` for recovery/attachment |
| `PaymentAuthorizations` | `capture`, `cancel` | Refreshed `PaymentAttempt` |
| `PaymentRefunds` | `refund` | Refreshed `PaymentRefund` |
| `PaymentReconciliation` | `reconcile`, `resolveException` | Refreshed `PaymentAttempt` |
| `PaymentWebhooks` | `process($payload, $signature)` | `void` |
| `PaymentReadService` | `forOrder($reference, $actor)` | Documented timeline array |

```php
$checkout = $checkouts->start($orderReference, $actor, $successUrl, $cancelUrl);
// ['sessionId' => string, 'url' => string, 'expiresAt' => CarbonImmutable]

$attempt = $authorizations->capture($attempt, $amountMinor, $operationId, $actor);
$attempt = $authorizations->cancel($attempt, $operationId, $actor);

$refund = $refunds->refund(
    $attempt,
    $amountMinor,
    'requested_by_customer',
    $internalNote,
    $operationId,
    $actor,
);

$attempt = $checkouts->recover($attempt, $stripeSessionId, $actor);
$attempt = $checkouts->attach($orderReference, $stripeReference, $operationId, $actor);
$attempt = $reconciliation->resolveException($attempt, $operationId, $actor);
```

Attempt parameters accept a `PaymentAttempt` or its ID; a malformed ID is reported as not found (`ModelNotFoundException`) on every database driver. Refund amounts are exact positive integer minor units. The nullable internal note is an explicit required argument: retain `null` or an unchanged UTF-8 string without NUL, at most 65,535 bytes. Empty strings remain distinct from null. Invalid notes reject before provider access or financial reservation. Notes use the native private TEXT field; the package does not encrypt or redact them. Keep stable UUIDs and identical inputs for retries; changed input under the same UUID is rejected.

Gateway hosted checkout, payment, checkout state and refund facts are native arrays with complete shapes documented on `PaymentGateway`. `OrderPaymentSnapshot` remains a validated immutable domain value object. Its JSON fingerprint contains the original order fact keys, independent of ownership admission metadata.

`PaymentReadService::forOrder()` authorizes before reading. Its array contains `orderReference`, ordered `payments` and `refunds`; payment facts include conservative reserved/refundable balances. Internal notes and operation credentials are omitted. It performs two payment-data queries; Core ownership metadata checks are separate.

## Consumer webhook endpoint

Register the host endpoint and pass the untouched request body and Stripe signature to the public service:

```php
Route::post('/payments/stripe/webhook', function (Request $request, PaymentWebhooks $webhooks) {
    $webhooks->process($request->getContent(), $request->header('Stripe-Signature', ''));

    return response('OK');
});
```

The service verifies the signing secret, freshness, envelope, account and mode before processing. It owns replay receipts and current Stripe reads. The consumer selects its route name, HTTP middleware and exception mapping. Doctor checks package readiness without requiring a specific consumer route name. Process webhooks outside an ambient Payments transaction so provider reads precede local locking. A browser checkout return is not payment evidence.

## Recovery and ownership

Remote calls are bracketed by local transactions and persisted operation IDs. Checkout, authorization and refund operations must start outside an existing transaction. Preserve unknown operations and reserved funds through provider timeouts; reconcile them using exact stored provider metadata before initiating another mutation. A Checkout request Stripe refuses outright (invalid request, rejected or restricted credential, rate limit) created no Session: the attempt is recorded as `failed`, its reservation is released and the next `start` creates a new Session. Timeouts, server errors and idempotency conflicts stay reserved.

Manual card capture is final, including partial capture. Stripe reports a released hold (a canceled or expired authorization, or the uncaptured remainder of a partial capture) on the charge as refunded; gateway payment facts exclude it, so `refundedAmountMinor` counts captured money only. Pending/unknown refunds reserve balance. Reconciliation correlates lost refund responses by operation metadata and complete payment identity, never by equal amounts. `payment_exception` keeps financial facts visible until separately authorized acceptance, preserving the original order revision. Domain notifications follow the source connection's outer commit and disappear on rollback.

Unowned hosts use ordinary unscoped declarations. Install native Ownership separately for isolated configured models and reviewed adoption. The trusted order owner and actor must match current admitted ownership. Disabling Ownership does not undo adopted storage protection. The retired Tenancy bridge is unavailable.

Run `nvl:payments:doctor --strict`, schedule `nvl:payments:reconcile`, and exercise actual Stripe test-mode checkout, capture, refund, replay and recovery before live use. With native Ownership, an owner refused system admission (for example a suspended owner) is reported and counted as failed; the sweep continues with the remaining owners and exits non-zero.

Doctor checks the configured writer's required amount columns, positive payment/refund amounts, captured/refunded counter bounds, and live local refund reservations against the original payment total. Failed and canceled refunds release that reservation. These read-only checks return booleans, including on unavailable or incomplete sources; they neither enforce database constraints nor prove agreement with current Stripe state. Investigate failures through authorized private host tooling and reconciliation before allowing further financial effects.

The creating migration's `down()` drops all four Payments tables, including operation journals and webhook replay receipts. Keep financial history under an explicit host retention and backup policy; use the recovery procedure in [UPGRADING.md](UPGRADING.md#financial-storage-retention-and-rollback) before any intentional database retirement. A routine application deployment rollback must preserve this storage.

## Testing your app

`Nvl\Payments\Testing\FakePaymentGateway::fake($container)` installs a fresh gateway before constructing services. Script each method with `willReturn` or `willThrow`; return documented fact arrays and lists of refund fact arrays, and use `null` for checkout expiration. Scripts are per-instance FIFO; closures remain inert, exhausted scripts fail and calls retain exact named arguments.

Gateway fakes avoid provider requests; actual service calls still exercise payment persistence, ownership and journals. Consumer Actions and HTTP fixtures belong in the host. The package does not ship application workflow interfaces, payload DTOs or routes.

## Configured root models

Set `nvl-payments.models.attempt` and `nvl-payments.models.refund` before Laravel boots to concrete subclasses of `PaymentAttempt` and `PaymentRefund`. Native services, factories, refund parent relations, restoration and Ownership declarations resolve the frozen configured classes. Operation journals, webhook receipts and lock records retain their package classes.

Extensions retain native UUID keys, required casts, query and persistence hooks, foreign keys, and the package's financial ownership strategy. Root and technical records must use the same actual package connection and their configured native tables; selecting a separate connection or table only on a subclass is rejected before effects. Configure package-level `connection` and `tables` options instead. Model replacement does not adopt existing storage or alter the native schema contract.

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
