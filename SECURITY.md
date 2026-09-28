# Security Policy

Report vulnerabilities through [private GitHub reporting](https://github.com/nvl-laravel-suite/payments/security/advisories/new). Include a minimal reproduction without real Stripe keys, webhook secrets, card data, customer details, or full webhook payloads.

Keep Stripe secrets server-side, verify the Payments webhook signature, use HTTPS return URLs, and require host authorization for every management action. Never trust a Checkout redirect, client-calculated amount, or raw Stripe ID as proof of payment or refund authority. Monitor failed webhooks and reconciliation, and resolve ambiguous operations before accepting another money movement.
