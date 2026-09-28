# Upgrading NVL Payments

Payments is a new optional package with no previous NVL Payments schema. Install it separately from `nvl/laravel-suite`; it needs only Core among NVL packages. Existing Billing subscriptions and Cashier customer models remain independent.

Before enabling an existing application's first Payments deployment, choose one migration source, bind `PaymentOrderProvider`, `PaymentManagementAccess`, and `ExistingPaymentOwnership`, configure the Stripe account and Payments-specific signed webhook, and run `nvl:payments:doctor --strict`. Keep Payments disabled until its schema and host policy are ready. Exercise Checkout, capture, refund, webhook replay, and reconciliation in Stripe test mode before live money movement.

If importing historical payments, require host ownership proof for every Stripe reference. Do not migrate an existing transaction merely because its amount matches an Order. Preserve operation UUIDs, attempt records, and refund history when changing the host Order schema or Stripe configuration; unresolved operations require reconciliation before new mutations.
