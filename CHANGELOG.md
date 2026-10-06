# Changelog

All notable changes to `nvl/payments` are documented here.

## [Unreleased]

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
