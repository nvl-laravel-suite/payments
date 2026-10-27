---
name: nvl-payments
description: Use when integrating or reviewing one-time Stripe order payments with nvl/payments, including Checkout, authorization, capture, refunds, webhooks, and reconciliation.
---

# NVL Payments

Use the native services in `Nvl\Payments\Services`. The host owns orders, prices, authorization, fulfillment, HTTP endpoints and response types. See the installed package README and service PHPDoc for exact signatures and native array shapes.

## Configure and admit

Select one migration source and publish the package configuration only when overriding it. Keep Payments disabled until schema, Stripe configuration and required host bindings are ready. Bind `PaymentOrderProvider`, `PaymentManagementAccess` and `ExistingPaymentOwnership`; defaults deny. Resolve integer minor-unit amounts, currency and order revision from trusted host facts.

Configure the Stripe account, live/test mode, Payments-specific webhook secret, allowed currencies and HTTPS return hosts. Ordinary unowned hosts retain unscoped declarations. Native Ownership is optional and requires its own configured resources and admission; it does not grant business permission.

## Native operations

- `PaymentCheckout`: `start`, `recover`, `attach`.
- `PaymentAuthorizations`: `capture`, `cancel`.
- `PaymentRefunds`: `refund`.
- `PaymentReconciliation`: `reconcile`, `resolveException`.
- `PaymentWebhooks`: `process` with the untouched request body and signature.
- `PaymentReadService`: authorized `forOrder` timeline arrays.

Pass explicit actors and stable operation UUIDs. Retry uncertain operations with the same UUID and identical inputs. The refund note remains an explicit nullable argument. `OrderPaymentSnapshot` is a validated domain value; gateway and reader facts use documented arrays. Applications may wrap these services in their own Actions or DTOs.

## Preserve recovery behavior

Start checkout, authorization and refund operations outside an existing transaction. Remote calls are bracketed by local transactions and persisted operation IDs. Keep unknown operations and reserved balances through provider timeouts, and reconcile exact stored provider metadata before initiating another mutation. A browser checkout return is not payment evidence.

Manual capture is final, including partial capture. Pending or unknown refunds reserve balance. Reconciliation matches complete payment identity and operation metadata, not equal amounts. Resolve `payment_exception` only through the separately authorized service operation. Preserve the original order revision, source connection and after-commit notification behavior.

The host owns its signed webhook endpoint and exception mapping. Schedule `nvl:payments:reconcile`, run `nvl:payments:doctor --strict`, and exercise actual Stripe test-mode checkout, capture, refund, replay and recovery before live use.

## Test the boundary

Install `Testing\FakePaymentGateway::fake($container)` before resolving services. Script documented native arrays using `willReturn` or `willThrow`; queues and call history are per instance, exhausted scripts fail and closures remain inert. Gateway fakes isolate provider requests. Real service integration tests must still prove authorization, persistence, reservations, rollback and recovery. No package HTTP, passive payload DTO or duplicate workflow interface is required.

## Configured financial models

Set `models.attempt` and `models.refund` to concrete package-model subclasses before boot. The frozen classes flow through native services, factories, refund relations, Ownership and restoration. Operations, webhooks and locks remain fixed.
Retain required UUID identity, casts, query/persistence hooks and financial ownership declarations. Subclass-only table, connection or foreign-key changes are rejected; package-level storage configuration still applies to all records. Model configuration does not replace reviewed native adoption.
