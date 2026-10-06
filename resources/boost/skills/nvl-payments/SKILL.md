---
name: nvl-payments
description: Use when integrating or reviewing one-time Stripe order payments with nvl/payments, including Checkout, authorization, capture, refunds, webhooks, and reconciliation.
---

# NVL Payments

Use this skill for an application consuming `nvl/payments`. The host owns Orders, calculated prices, admission, fulfillment, and the UI; Payments owns durable Stripe payment facts and operations.

## Integrate

- Install `nvl/payments:^5.0`, publish `nvl-payments-config` and `nvl-payments-skills`, then choose vendor-loaded or published migrations. Keep `nvl-payments.enabled=false` during `php artisan migrate`: vendor mode sets `nvl-payments.migrations.enabled=true`, while published mode leaves it false. Never enable both migration sources; enable payment routes after schema, host bindings, and Stripe setup.
- Bind `PaymentOrderProvider`, `PaymentManagementAccess`, and `ExistingPaymentOwnership` in the host. Defaults deny. Resolve amount and currency from server-owned Order facts and prove ownership before importing an existing payment.
- Configure the Stripe key, account ID, test/live mode, Payments-specific webhook secret, allowed currencies, and HTTPS return hosts. Enable Payments only after schema and bindings are ready.
- Redirect customers to the hosted Checkout URL. Treat signed webhook or reconciliation state as payment evidence; a browser return is not evidence.

## Inject and test complete workflows

- Inject `StartCheckoutContract`, `AttachExistingPaymentContract`, `RecoverCheckoutContract`, `CapturePaymentContract`, `CancelAuthorizationContract`, `RefundPaymentContract`, and `ResolvePaymentExceptionContract` from `Nvl\Payments\Contracts` for the matching complete management workflows. Each `execute` retains its native parameters and immutable result type. `RefundPaymentContract` keeps the nullable note required.
- Inject `PaymentReadContract` and call `forOrder($orderReference, $actor)` for the declared `OrderPaymentTimeline`. Continue using the existing four gateway/order/access/ownership extension contracts for their host responsibilities.
- Defaults use transient `bindIf` registrations. Host instances and closures installed before discovery survive registration. A late replacement affects newly resolved host services; existing services keep their injected dependency. Concrete Actions and `PaymentReadService` retain their native constructors and private chains through major 5.
- For host orchestration tests, instance-bind an interface mock or implementation, return a real `HostedCheckout`, `PaymentSnapshot`, `RefundSnapshot`, or `OrderPaymentTimeline`, and resolve the host service from the container. Assert exact inputs and host decisions. Guard owning implementation/gateway/order/access/SDK/storage resolution and measure effects after setup. Such substitution does not prove authorization, durable lifecycle behavior, or Stripe delivery; retain owning integration and test-mode checks. The README includes constructor injection and a native timeline fixture example.

## Runtime gateway fake

- Install Testing\FakePaymentGateway::fake($container) before resolving host services. It implements PaymentGateway's exact nine methods, uses Core major 5 runtime FakeCalls and preserves host-before-discovery/late fresh-resolution behavior without provider edits.
- Script each native method explicitly with willReturn/willThrow; FIFO values/exceptions and history are per-instance. Return actual HostedCheckout/Stripe states and list<StripeRefundState>; use null for expireCheckout's void script. Closure values stay inert and wrong result types raise TypeError.
- assertCalled predicates receive immutable Nvl\Support\Testing\FakeCall with method/arguments; inspect exact order identity, amountMinor, currency and idempotencyKey. Counts are exact/non-negative, including zero; unknown names and exhaustion fail. Records retain original object handles without serialization.
- Prepare fixtures before effect guards and guard the Stripe SDK transport as well as Laravel HTTP/SQL/storage/queue. Fakes perform no real gateway/persistence work; owning Actions still write lifecycle state, and fake results do not prove payment/authorization safety. Keep native schema, webhook/reconciliation and Stripe test-mode coverage.

## Money movement and recovery

- Use stable operation UUIDs for capture, cancellation, attachment, and refund. Retry the same UUID with identical input after uncertainty; reconcile before starting a different mutation.
- Manual card capture is one final capture. A partial amount releases the remainder. Refund only confirmed captured balance; pending and unknown refunds reserve balance. Show refund status accurately, including pending, failed, canceled, succeeded, and requires action.
- The `recover_checkout` permission is required to recover an ambiguous Checkout Session, with exact stored operation-key and Stripe account/mode/order/amount proof.
- Keep `payment_exception` visible while syncing confirmed captured/refunded facts. Host `refund` permission permits refunding its confirmed funds. Explicit acceptance uses `ResolvePaymentExceptionAction::execute($attemptId, $actor, $operationId)` and the separate `resolve_payment_exception` permission, with a durable actor journal and after-commit event. Preserve the original revision; the host decides how to apply the accepted money.
- Reconcile a lost refund response by exact `payment_operation_key` metadata plus account/mode/payment/currency/amount/reason and unique refund identity. Never infer ownership from equal amounts or clear unknown reservations without proof.
- After an async Checkout failure, reconcile and retry `StartCheckoutAction`. It releases only a freshly confirmed closed unpaid Session with a non-collectible PaymentIntent (`requires_payment_method` or `canceled`, zero captured/refunded/capturable), or an expired Session without a PaymentIntent. Open Sessions require verified expiration using the same persisted key through timeouts. Processing/authorized/paid/ambiguous results stay blocked. Do not directly cancel Checkout-owned failed PaymentIntents; Stripe forbids it. Closed-flow safety follows the documented Session lifecycle/direct-confirmation restriction and must be exercised in Stripe test mode.
- Host operation names are `start_checkout`, `attach_existing`, `capture`, `cancel_authorization`, `refund`, `recover_checkout`, `resolve_payment_exception`, and `view`; deny unknown operations.
- Register the separate signed Payments webhook endpoint, schedule `nvl:payments:reconcile`, and run `nvl:payments:doctor --strict` at deployment. Exercise Checkout, capture, refund, and webhook retry in Stripe test mode before live use.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-payments.tables.*`, connections through `nvl-payments.connection` with Core/Laravel inheritance. Defaults use `nvl_payments_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=payments --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Canonical configuration ownership

- Read/write `nvl-payments` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.


## Consumer runtime and testing contracts

Start with the package README Quickstart and Testing your app sections. Use `nvl:install <package>` for loaded-package common config publication; it does not enable features, run schema or refresh caches. Preserve native host owner keys/morph maps and selected auth/tenancy defaults. Read full runtime defaults and publish advanced config only deliberately.

Inject the supported focused interfaces and preserve host bindings. Returned model handles do not permit package-table queries/writes outside documented capability/extension seams. Host tests may substitute contracts in Laravel's container, use shipped model factories (ordinary make may persist parents; withoutParents()->make is detached), and use Laravel effect fakes deliberately. Only Media/Stripe have dedicated provider/library fakes; do not invent a universal package fake. Settings InteractsWithSettings is definition-only. Host PHPStan may include vendor/nvl/core/support/consumer-audit.neon; no unpublished workbench command is a consumer requirement.

Read docs/events.md and the package README error table. Domain events use schemaVersion=1, model-free facts and actual source-connection commit callbacks; only six declared old Event suffix aliases remain for major 5. Migrate exact listeners/fakes and suffix wildcards, drain old queued payloads, rebuild event cache and restart workers. Delivery is not a durable outbox. The Core exception renderer is opt-in, JSON-only for respondable failures, with exactly message/code/context and host-selected locale. Do not expose diagnostics or reinterpret missing bindings as authorization denial.

Core package logging uses nvl/normal with CSV quiet by default, stable message keys and bounded context; incidents survive quiet. Do not mutate global logger context or log raw row/provider/content/credential payloads. Run only authorized project checks and report new acceptance as pending until actual output exists.
