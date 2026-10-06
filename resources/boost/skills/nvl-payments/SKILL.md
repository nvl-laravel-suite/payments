---
name: nvl-payments
description: Use when integrating or reviewing one-time Stripe order payments with nvl/payments, including Checkout, authorization, capture, refunds, webhooks, and reconciliation.
---

# NVL Payments

Use this skill for an application consuming `nvl/payments`. The host owns Orders, calculated prices, admission, fulfillment, and the UI; Payments owns durable Stripe payment facts and operations.

## Integrate

- Install `nvl/payments:^2.0`, publish `nvl-payments-config` and `nvl-payments-skills`, then choose vendor-loaded or published migrations. Keep `payments.enabled=false` during `php artisan migrate`: vendor mode sets `nvl-payments.migrations.enabled=true`, while published mode leaves it false. Never enable both migration sources; enable payment routes after schema, host bindings, and Stripe setup.
- Bind `PaymentOrderProvider`, `PaymentManagementAccess`, and `ExistingPaymentOwnership` in the host. Defaults deny. Resolve amount and currency from server-owned Order facts and prove ownership before importing an existing payment.
- Configure the Stripe key, account ID, test/live mode, Payments-specific webhook secret, allowed currencies, and HTTPS return hosts. Enable Payments only after schema and bindings are ready.
- Redirect customers to the hosted Checkout URL. Treat signed webhook or reconciliation state as payment evidence; a browser return is not evidence.

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
