# Changelog

## Unreleased — consumer runtime integration

- Added focused consumer contract/testing guidance and shipped-factory usage limits.
- Versioned committed event payloads and documented canonical aliases, source connections, failure metadata and optional safe rendering.
- Added explicit first-use/installer and deployment guidance; new acceptance checks remain pending.


All notable changes to `nvl/payments` are documented here.

## [Unreleased]

### Added

- Add a runtime FakePaymentGateway with exact native methods, instance-owned FIFO scripting, immutable named call records and explicit container installation; no Stripe or persistence execution occurs in the fake.

- Add seven focused management Action contracts and `PaymentReadContract` for constructor injection and host workflow substitution, with transient conditional defaults that preserve host bindings and existing concrete APIs.

### Changed

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Prepare lockstep major 5 with required and development NVL peer floors of `^5.0`. This candidate has not been tagged or published.
- Move configuration, env inputs and public package names to the canonical NVL namespace.
- Remain an optional installation outside the 21-member suite metapackage.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.0.0] - 2026-09-28

### Added

- Optional one-time Stripe order payments with hosted Checkout, signed webhooks, manual final capture, cancellation, existing-payment attachment, and full or partial refunds.
- Durable idempotent operations, conservative refund reservations, reconciliation, doctor diagnostics, and host-owned authorization contracts.
- Opt-in migrations, independent Billing coexistence, and packaged consumer guidance.
