# Upgrading NVL Payments

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. The PHP 8.4/Laravel 13 local Dagger release gate and fresh public Composer installation passed for 5.0.0. Additional compatibility legs need separate evidence; hosts must verify their own adoption.


## Major 5: runtime gateway fake

`Nvl\Payments\Testing\FakePaymentGateway` implements the existing PaymentGateway without changing the production provider, Action constructors or gateway signatures. Install it with `fake($container)` before constructing host services; late replacement affects new resolutions. It requires the Core major 5 runtime FakeCalls helpers, queues explicit per-method FIFO results/exceptions and records named arguments before every attempt. Use null scripts for expireCheckout and actual native value objects for typed responses. Wrong types, unsupported names and exhausted scripts fail clearly; predicates receive immutable Core FakeCall records. No schema or data migration is required. See [Testing your app](README.md#testing-your-app), and retain real lifecycle and transport coverage.

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

## Major 5: focused workflow contracts

Application services can now inject `Nvl\Payments\Contracts\StartCheckoutContract`, `AttachExistingPaymentContract`, `RecoverCheckoutContract`, `CapturePaymentContract`, `CancelAuthorizationContract`, `RefundPaymentContract`, and `ResolvePaymentExceptionContract` instead of their matching final concrete Actions. Inject `PaymentReadContract` instead of `PaymentReadService` for authorized order timelines. Each interface copies the current public method and result type; the nullable refund note remains a required argument.

These additions require no schema or data migration. Existing concrete constructors and workflow behavior remain supported through major 5. Defaults are transient conditional bindings, preserving host instances and closures registered before discovery. Late replacement affects newly resolved application services. Keep `PaymentGateway`, `PaymentOrderProvider`, `PaymentManagementAccess`, and `ExistingPaymentOwnership` for existing integration responsibilities; internal orchestration dependencies remain package-owned. See the README for constructor injection and host test substitution.
