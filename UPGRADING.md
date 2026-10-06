# Upgrading NVL Payments

Payments is a new optional package with no previous NVL Payments schema. Install it separately from `nvl/laravel-suite`; it needs only Core among NVL packages. Existing Billing subscriptions and Cashier customer models remain independent.

Before enabling an existing application's first Payments deployment, choose one migration source, bind `PaymentOrderProvider`, `PaymentManagementAccess`, and `ExistingPaymentOwnership`, configure the Stripe account and Payments-specific signed webhook, and run `nvl:payments:doctor --strict`. Keep Payments disabled until its schema and host policy are ready. Exercise Checkout, capture, refund, webhook replay, and reconciliation in Stripe test mode before live money movement.

If importing historical payments, require host ownership proof for every Stripe reference. Do not migrate an existing transaction merely because its amount matches an Order. Preserve operation UUIDs, attempt records, and refund history when changing the host Order schema or Stripe configuration; unresolved operations require reconciliation before new mutations.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=payments --claim-legacy --dry-run --format=json
php artisan nvl:schema:upgrade --package=payments --claim-legacy --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Unmodified published files, including changed timestamps, map by verified checksum to the exact vendor migration identity and current package migration implementation. Modified host copies remain host-owned. Disable vendor loading when retaining a published owner; duplicate ownership fails before migration. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.
