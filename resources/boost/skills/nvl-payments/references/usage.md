# One-time payment usage

Use [installation](installation.md) for setup/publication and [API](api.md) for complete signatures, gateway fact arrays and domain values. Service namespace: `Nvl\Payments\Services`.

## Required host contracts

- `PaymentOrderProvider::resolve(string $orderReference): OrderPaymentSnapshot` reloads trusted order facts. Construct `Nvl\Payments\ValueObjects\OrderPaymentSnapshot(reference, revision, amountMinor, currency, label, email, payable, owner: null)`. Reference/revision are nonblank strings, amount is positive integer minor units, currency is a three-letter ISO code, email is valid or null, and owner is a native `OwnerReference` when applicable. Do not accept request-provided totals, revision or owner as authoritative.
- `PaymentManagementAccess::assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void` throws on denial. Operation aliases are `start_checkout`, `recover_checkout`, `attach_existing`, `capture`, `cancel_authorization`, `refund`, `resolve_payment_exception`, `view`.
- `ExistingPaymentOwnership::assertOwned($order, $payment)` validates trusted host evidence for imports. The second argument is the complete gateway payment-fact array, not an arbitrary provider response.

Bind contracts before services resolve. Eloquent actors are canonicalized before host authorization; other providers own freshness. Native Ownership is optional and supplies admission separately from business permission. Trusted snapshot owner, current admitted owner and actor must agree.

## Select the workflow

| Need | Native call | Result |
| --- | --- | --- |
| Hosted checkout | `PaymentCheckout::start(orderReference, actor, successUrl, cancelUrl)` | `sessionId`, `url`, `expiresAt` |
| Lost/uncertain checkout response | `PaymentCheckout::recover(attempt, stripeSessionId, actor)` | `PaymentAttempt` |
| Import an existing Stripe payment | `PaymentCheckout::attach(orderReference, stripeReference, operationId, actor)` | `PaymentAttempt` after ownership evidence |
| Manual capture | `PaymentAuthorizations::capture(attempt, amountMinor, operationId, actor)` | Refreshed attempt |
| Cancel authorization | `PaymentAuthorizations::cancel(attempt, operationId, actor)` | Refreshed attempt |
| Refund | `PaymentRefunds::refund(attempt, amountMinor, reason, note, operationId, actor)` | `PaymentRefund` |
| Refresh provider truth | `PaymentReconciliation::reconcile(attempt)` | Refreshed attempt |
| Accept `payment_exception` | `PaymentReconciliation::resolveException(attempt, operationId, actor)` | Separately authorized resolution |
| Provider delivery | `PaymentWebhooks::process(payload, signature)` | Verified state/replay processing |
| Host payment history | `PaymentReadService::forOrder(orderReference, actor)` | `orderReference`, ordered `payments`, `refunds` |

Attempt parameters accept the configured `PaymentAttempt` model or string ID. Unknown payload keys do not enter these scalar service operations. Use the API reference for every provider fact array; preserve its nullable keys rather than pruning nulls or forwarding the provider SDK object.

## Verified checkout and refund examples

Preconditions: enabled/configured Payments, required host bindings, an existing payable order, admitted `$actor`, and allowlisted HTTPS return host. Calls must be outside an existing Payments transaction:

```php
use Nvl\Payments\Services\PaymentCheckout;
use Nvl\Payments\Services\PaymentReadService;

$checkout = app(PaymentCheckout::class)->start(
    'order-1042',
    $actor,
    'https://app.example.com/orders/1042/paid',
    'https://app.example.com/orders/1042',
);
// Redirect the browser to $checkout['url'].

$timeline = app(PaymentReadService::class)->forOrder('order-1042', $actor);
// Use synchronized payment facts for fulfillment, not the browser return URL.
```

For a captured attempt selected from trusted order history, persist the UUID with the host refund request before calling; reuse it for every retry:

```php
use Nvl\Payments\Services\PaymentRefunds;

$refund = app(PaymentRefunds::class)->refund(
    $attempt,
    2500,
    'requested_by_customer',
    null,
    $persistedRefundOperationUuid,
    $actor,
);
```

`note` is required even when null. `reason` is exactly `duplicate`, `fraudulent` or `requested_by_customer`. The amount is a positive integer in the original currency's minor units; no floating conversions or currency substitution. Changed amount/reason/note under a reused operation UUID is rejected. Manual capture is final even for a partial amount; do not assume the unclaimed authorization remains available.

## Provider configuration and replay

Configure `stripe.secret`, `stripe.webhook_secret`, `stripe.account_id`, `stripe.livemode`, `allowed_currencies` and `checkout.return_hosts` under `nvl-payments`. Return URLs must use exact approved HTTPS hosts, without credentials or a nonstandard port. The host chooses automatic versus manual capture through `checkout.capture_method` and checkout expiry policy.

Pass `$request->getContent()` and the unmodified Stripe signature header to `PaymentWebhooks::process`. Signature, freshness, account/mode and envelope validation precede persisted effects. The host owns its endpoint, middleware and error mapping; no package route is installed. Process outside an ambient source transaction so remote reads precede local locking.

Native mutations persist operation identity before provider calls. Unknown/uncertain outcomes remain visible for reconciliation; retain reservations instead of initiating another request with a fresh UUID. Pending and unknown refunds consume refundable balance. Reconciliation correlates complete payment identity and exact operation metadata; equal amounts are insufficient. `payment_exception` requires explicit host-authorized acceptance and preserves the original order revision. Source-aware events follow outer commit, and rollback discards their local publication.

## Queries, extensions and operation support

`PaymentReadService::forOrder` authorizes before reading, returns conservative reserved/refundable balances, and omits internal notes and operation credentials. It uses two payment-data queries; independent Core metadata/admission probes are outside that count. Project returned facts for host responses instead of serializing raw records.

Configure `models.attempt` / `models.refund` before boot with subclasses of `PaymentAttempt` / `PaymentRefund`. Preserve UUIDs, casts, query/persistence hooks, financial ownership and native foreign keys. Operations, receipt and lock rows stay package-owned. The complete graph uses package-configured tables and actual connection; subclass-only storage changes reject. Model replacement is separate from reviewed schema/data adoption.

`Nvl\Payments\Testing\FakePaymentGateway::fake($container)` installs a fresh instance before resolving services. Use `willReturn` / `willThrow` with complete native fact arrays. Scripts are FIFO per instance, exhausted scripts fail, closures remain inert, and calls retain named arguments. Gateway substitution does not prove authorization, SQL rollback or durable recovery.

Use `nvl:payments:doctor --strict --format=json` and `nvl:payments:reconcile --payment=<attempt-UUID>`; omission of `--payment` uses the native bounded worklist. Provider failures, `PaymentsException`, authorization and invalid-input errors need deliberate host exception mapping. Do not expose diagnostic messages or retry uncertain finance operations through a generic controller retry loop.
