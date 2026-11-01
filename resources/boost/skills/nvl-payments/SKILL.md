---
name: nvl-payments
description: Use when integrating one-time Stripe order payments with nvl/payments, including hosted Checkout, manual capture, cancellation, refunds, verified webhooks, recovery and authorized timelines.
---

# NVL Payments

Payments targets PHP 8.4 and Laravel 13 and requires Core and Stripe PHP.
Billing, Cashier, Tenancy and DTO libraries are not required.
The host owns orders, pricing, authorization, fulfillment and HTTP.
Native services own provider identity, operation journals, balance reservations and reconciliation.

## Portable references

- [Installation](references/installation.md): provider discovery, exact publish tags, migration ownership and storage setup.
- [Native API](references/api.md): all public operations, gateway fact shapes, models and domain values.
- [Usage](references/usage.md): required bindings, operation selection, complete examples and uncertain-response recovery.

Read installation for a new host or adoption task and usage before composing operations.
Use the API's exact signatures and gateway array shapes instead of inferring a generic payment DTO.
The references remain usable after Laravel Boost publishes the skill.

## Configure trusted order facts

Bind `PaymentOrderProvider`, `PaymentManagementAccess` and `ExistingPaymentOwnership`.
Defaults deny until the host supplies order and ownership evidence.
`OrderPaymentSnapshot` is a validated domain value with server-calculated integer minor units.
Its order revision and owner come from trusted host data.
Configure Stripe account/mode, Payments-specific webhook secret, allowed currencies and HTTPS return hosts.
Enable Payments only when its schema and bindings are ready.

## Choose the complete operation

- `PaymentCheckout::start`: reserve or reuse an exact hosted checkout.
- `PaymentCheckout::recover`: recover a stored attempt using a Stripe session identity.
- `PaymentCheckout::attach`: import a provider payment only after explicit host ownership evidence.
- `PaymentAuthorizations::capture` / `cancel`: settle or cancel an authorization.
- `PaymentRefunds::refund`: reserve balance and issue an idempotent refund.
- `PaymentReconciliation::reconcile` / `resolveException`: synchronize facts or explicitly accept an exception.
- `PaymentWebhooks::process`: verify untouched signed request bytes and deduplicate.
- `PaymentReadService::forOrder`: authorized, bounded timeline facts.

## Keep financial recovery intact

Start checkout, capture/cancel and refunds outside an existing Payments source transaction.
Provider calls are bracketed by native local transactions and persisted operation identities.
Pass stable operation UUIDs and identical facts on retry.
The refund note is an explicit required nullable argument.
Manual capture is final, including partial capture.
Pending/unknown refunds reserve balance until reconciled.
Preserve unknown operations after provider timeouts; match complete stored identity and metadata.
A browser checkout return is not payment evidence.

## Actor, owner and model extension

Pass explicit actors; Eloquent policy inputs are reloaded from canonical storage.
An owner reference does not grant management permission.
Optional native Ownership requires registered resources, adoption and current admission.
Configure `models.attempt` and `models.refund` before boot using concrete package subclasses.
Retain native UUIDs, casts, persistence/query hooks, foreign keys and financial ownership.
Operations, receipts and locks remain fixed native records.
Configure the whole graph's connection/tables; subclass-only storage splits are rejected.

## Results and diagnostics

Checkout returns `sessionId`, `url`, `expiresAt`; other mutations return refreshed payment/refund models.
Reader arrays omit internal notes and operation credentials.
The host chooses public projections and maps package failures.
Use `FakePaymentGateway::fake($container)` before resolving services for provider-isolated tests.
Run `nvl:payments:doctor --strict`; reconcile through `nvl:payments:reconcile`.
Read usage for replay rules and source-backed checkout/refund examples.
