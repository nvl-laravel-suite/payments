---
name: nvl-payments
description: Use when integrating or reviewing one-time Stripe order payments with nvl/payments, including Checkout, authorization, capture, refunds, webhooks, and reconciliation.
---

# NVL Payments

Use this skill for an application consuming `nvl/payments`. The host owns Orders, calculated prices, admission, fulfillment, and the UI; Payments owns durable Stripe payment facts and operations.

## Integrate

- Install `nvl/payments:^2.0`, publish `payments-config` and `payments-skills`, then choose vendor-loaded or published migrations. Keep `payments.enabled=false` during `php artisan migrate`: vendor mode sets `payments.migrations.enabled=true`, while published mode leaves it false. Never enable both migration sources; enable payment routes after schema, host bindings, and Stripe setup.
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
