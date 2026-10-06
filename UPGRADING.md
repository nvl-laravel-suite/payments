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
php artisan nvl:schema:upgrade --package=payments --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=payments --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

The implementation Actions `ProcessPaymentsWebhookAction`, `ReconcilePaymentAction` are explicitly internal. Deliver provider webhooks to the configured package webhook endpoint and run `nvl:payments:reconcile` for reconciliation; use `StartCheckoutAction`, `RecoverCheckoutAction`, and the supported payment mutation Actions for application workflows.
