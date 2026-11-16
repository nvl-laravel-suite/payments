# Stripe deployment and recovery

## Go live

- Apply the additive Payments migrations with the selected migration owner. Back up payment and host-ledger tables first.
- Bind trusted order resolution and management policy. Check the host ledger's unique fact receipt and rollback behavior.
- Set the Stripe secret and publishable key for the intended account and mode. Live requires both `stripe.livemode` and `allow_live`. Test mode is the default. Call `PaymentsAvailability::acceptsPayments()` before rendering a payment entry point.
- Configure currencies and minimums, capture mode, HTTPS hosted return domains and, for Payment Element wallets, Stripe payment method domains.
- Use a dedicated Stripe webhook signing secret and register the exact events returned by `PaymentWebhooks::eventTypes()`. Set `webhooks.url` to that endpoint's public HTTPS URL.
- Enable `webhooks.route` to register the package endpoint. It returns 404 when disabled or missing a signing secret, 400 for invalid signatures, and 204 after authenticated intake. For a host route, use `PaymentWebhooks::enqueue()` with unchanged body bytes and the `Stripe-Signature` header. Existing `process()` remains available and follows the configured queue, including inline processing on Laravel's sync driver.
- Configure `webhooks.connection` and `webhooks.queue` for an asynchronous worker. Schedule `nvl:payments:retry` for webhook and ledger recovery and `nvl:payments:reconcile` for settlement and expiry.
- Run `php artisan nvl:payments:doctor --strict --stripe --format=json`. It checks the Stripe account country and currency, exact endpoint registration, key mode, signing configuration and queue. Configure expected values with `stripe.country` and `stripe.default_currency` if the deployment requires specific values. Run without `--stripe` for a local check when an endpoint URL is unset.
- Exercise a signed event, full payment, decline, canceled attempt, partial refund and replay in Stripe test mode before switching credentials.

The inbox stores signed envelope identifiers and operational status. The
processor reads current objects from Stripe. Existing scoped processing receipts
remain available for ownership history. Unsupported events and
disallowed live-mode events are recorded as ignored. Failed or pending
receipts are retried up to `webhooks.max_attempts`. The sweep also detects
interrupted claims after `webhooks.stale_after_minutes`. Use stable
database sessions for PostgreSQL advisory locks, including session pooling
when a pooler is present. SQLite workers share a filesystem for their local
locks. Watch pending, processing and failed backlog and investigate refusals
or exhausted attempts. Use authorized reconciliation to repair missed
payments when a receipt is exhausted.

The package pins Stripe API version `2026-10-28.endive`. Review the Stripe
changelog and SDK request/response types before changing the pin. Update it
in `StripePaymentGateway::API_VERSION`, update transport fixtures and
rehearse PaymentIntent, Checkout, cancellations, refunds and webhook delivery
on the new version in test mode. Move endpoint API versions in Stripe
Workbench after checking the signed envelope and replaying representative
events.

## Backups and retention

Retain payment attempts, operations, refunds, provider receipts, Stripe
customer mappings and ledger facts according to the host's accounting,
privacy and incident recovery requirements. Base retention on the host's
replay and backup windows. Keep the deduplication receipt for as long as
its fact can be replayed. Encrypt backups and restrict access to payments
and ledger data.

Pause intake, workers, reconciliation and host financial writes before a
coordinated restore. Restore the payment attempts first, then operations and
refunds, then customer mappings, provider receipts and outbox. Restore the
matching host ledger and its unique projection receipts before replaying
provider work. Use a consistent database snapshot when possible. Resume
after reconciling Stripe activity that took place after the backup. Replay
delivered facts explicitly when a host ledger was restored to an older point.

Financial migration rollbacks refuse by default. The explicit
`migrations.allow_destructive_rollback` flag permits destructive rollback on
an expendable database. Previously published host copies of the original
migration require the same guard in their existing `down()` method.

Stripe references [webhook signatures](https://docs.stripe.com/webhooks/signature),
[event types](https://docs.stripe.com/api/events/types),
[endpoint registration](https://docs.stripe.com/api/webhook_endpoints/list),
[accounts](https://docs.stripe.com/api/accounts/object) and
[API versioning](https://docs.stripe.com/api/versioning).
