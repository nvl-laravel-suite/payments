# NVL Payments — API and usage

[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/payments/issues). Report vulnerabilities through [private reporting](https://github.com/nvl-laravel-suite/payments/security/advisories/new). See [Contributing](CONTRIBUTING.md) and [Upgrading](UPGRADING.md).

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/payments:^2.0` |
| Module identifier | `nvl/payments` |
| PHP namespace | `Nvl\Payments` |
| Service provider | `Nvl\Payments\Providers\PaymentsServiceProvider` |
| Configuration | `config/payments.php` |

## Purpose and boundaries

Payments owns Checkout attempts, authorizations, captures, cancellations, attached Stripe payments, refunds, signed webhooks, reconciliation, and an order payment timeline. The host owns Orders, calculated amounts, customer admission, management authorization, fulfillment, and UI. A Checkout return URL does not prove payment; use confirmed package state and its after-commit `PaymentStateChanged` event. This package makes no money movement until explicitly configured and enabled.

Payments requires PHP 8.4, Laravel 13, `nvl/core:^2.0`, and Stripe PHP. It has no dependency on Tenancy, Billing, or Cashier. It does not register Cashier models or change Cashier routes. The optional `nvl/billing` package handles tenant subscriptions separately; both may coexist with separate webhook URLs and signing secrets. The suite metapackage does not install Payments.

## Requirements and installation

After its first public mirror tag is indexed on Packagist, install the published package in the host Laravel application:

```bash
composer require nvl/payments:^2.0
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-skills
```

Choose **one** migration source. For vendor-loaded migrations, leave `payments.enabled=false`, set `payments.migrations.enabled=true`, and do not publish `payments-migrations`; the provider loads vendor migrations independently of the payment routes. For host-owned migrations, run `php artisan vendor:publish --tag=payments-migrations`, leave `payments.enabled=false` and `payments.migrations.enabled=false`, and maintain the copied migration in the application. Never run both sources. Set `payments.connection` to a configured database connection or leave it `null` for Laravel's default; Payments does not use Tenancy's connection implicitly. Run `php artisan migrate` while Payments is still disabled, then configure the host bindings and Stripe endpoint before setting `payments.enabled=true`.

Bind all three host contracts in an application service provider: `PaymentOrderProvider` returns a trusted `OrderPaymentSnapshot` with server-calculated minor-unit amount, ISO currency, revision and payable flag; `PaymentManagementAccess` checks the authenticated actor and specific operation for that order; `ExistingPaymentOwnership` positively proves that an imported Stripe payment belongs to the order. The default implementations deny all three operations. For example, register your own implementations with `$this->app->bind(\Nvl\Payments\Contracts\PaymentOrderProvider::class, \App\Payments\OrderPaymentProvider::class)` and likewise bind `PaymentManagementAccess` and `ExistingPaymentOwnership`. Do not accept a browser-supplied amount or use a matching amount as ownership proof.

For example, this host adapter resolves the exact order and delegates each operation to a host Order policy. Unknown operations, missing orders, and denied policy decisions fail closed:

```php
use App\Models\Order;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Nvl\Payments\Contracts\PaymentManagementAccess;
use Nvl\Payments\ValueObjects\OrderPaymentSnapshot;

final class OrderPaymentAccess implements PaymentManagementAccess
{
    public function assertCanManage(Authenticatable $actor, string $operation, OrderPaymentSnapshot $order): void
    {
        $ability = match ($operation) {
            'start_checkout' => 'pay',
            'view' => 'viewPayments',
            'attach_existing' => 'attachPayment',
            'capture' => 'capturePayment',
            'cancel_authorization' => 'cancelAuthorization',
            'refund' => 'refundPayment',
            'recover_checkout' => 'recoverCheckout',
            'resolve_payment_exception' => 'resolvePaymentException',
            default => null,
        };
        $hostOrder = Order::query()->where('reference', $order->reference)->first();

        if ($ability === null || $hostOrder === null || Gate::forUser($actor)->denies($ability, $hostOrder)) {
            throw new AuthorizationException('Payment operation is not allowed for this order.');
        }
    }
}
```

Register those abilities in the host Order policy and bind this class as `PaymentManagementAccess`. Customer `pay` permission and administrator capture/refund permissions can differ by actor, operation, and order state.

Set `PAYMENTS_STRIPE_SECRET`, `PAYMENTS_STRIPE_WEBHOOK_SECRET`, and `PAYMENTS_STRIPE_ACCOUNT_ID` in the host environment. Configure `payments.stripe.livemode` deliberately, allowlisted `payments.allowed_currencies`, and HTTPS `payments.checkout.return_hosts`. The configured account ID and mode must match the Stripe objects used by this installation. Set `payments.checkout.capture_method` to `automatic` or `manual`. Enable `payments.enabled=true` only after schema, bindings, Stripe credentials and webhook are ready. Run `php artisan nvl:payments:doctor --strict`; `--format=json` gives machine-readable checks without printing secrets.

## Checkout and management actions

Resolve an Order through the host provider and invoke `StartCheckoutAction::execute($orderReference, $actor, $successUrl, $cancelUrl)`. The URLs must use HTTPS and an allowlisted return host. Redirect to the returned hosted URL. An order revision change, an ambiguous Stripe outcome, or a previously paid attempt may block a new Checkout until the original attempt is reconciled. Never mark the Order paid from a browser redirect.

For a confirmed manual card authorization, `CapturePaymentAction::execute($attemptId, $actor, $amountMinor, $operationId)` makes one **final** capture. A partial amount releases the unused authorization; multicapture is unsupported. `CancelAuthorizationAction::execute($attemptId, $actor, $operationId)` cancels while Stripe permits it. These actions require an internal attempt UUID and a stable operation UUID; retry the same operation UUID with identical input after a timeout. Both enforce current Stripe state and host permission.

`AttachExistingPaymentAction::execute($orderReference, $actor, $stripeReference, $operationId)` accepts a `pi_`, `ch_`, or payment-sourced `txn_` reference only after host ownership proof. For captured payments, `RefundPaymentAction::execute($attemptId, $actor, $amountMinor, $reason, $note, $operationId)` supports full and repeated partial refunds up to the confirmed unrefunded captured amount. Amounts are integer minor units. Use a Stripe-supported reason and keep the optional note internal. A pending refund remains pending; `succeeded`, `failed`, `canceled`, and `requires_action` represent distinct outcomes. Pending and ambiguous reservations reduce the available refundable balance until authoritative reconciliation. Do not grant a second refund because a network call timed out.

A `payment_exception` preserves the original order revision and blocks another Checkout while continuing to expose confirmed captured/refunded amounts. An administrator with the host `refund` permission may refund that confirmed money through `RefundPaymentAction` without accepting the exception. To accept it explicitly, call `ResolvePaymentExceptionAction::execute($attemptId, $actor, $operationId)` with the separate `resolve_payment_exception` permission. This verifies current Stripe money, records an immutable actor/operation journal entry, and emits an after-commit transition from the exception to its financial state. Retry the same operation UUID with the same actor. Acceptance does not rewrite the attempt's original order revision or update the host Order; the host decides how to apply this explicitly accepted payment.

Read an order timeline through `PaymentReadService` after `view` authorization. The timeline includes attempts, Stripe references, captured and conservatively refundable amounts, refunds, and sync time. Management actions and storefront/admin routes remain host-owned; Payments exposes only its signed webhook route.

## Webhooks and recovery

Register `POST /nvl/payments/stripe/webhook` as a separate Stripe endpoint with the Payments webhook secret. Select `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `payment_intent.succeeded`, `payment_intent.processing`, `payment_intent.payment_failed`, `payment_intent.canceled`, `payment_intent.amount_capturable_updated`, and `charge.refunded`. Refund status changes are also repaired by reconciliation. Keep signature verification enabled and exempt this endpoint from session CSRF middleware. The package deduplicates event IDs and reads authoritative Stripe facts before changing financial state.

Schedule `php artisan nvl:payments:reconcile` and use `php artisan nvl:payments:reconcile --payment=<attempt-uuid>` for a targeted repair after a missed webhook, out-of-order delivery, direct Dashboard refund, or ambiguous API result. An unresolved Checkout create call stays blocked. To recover a Checkout Session found in Stripe, `RecoverCheckoutAction::execute($attemptId, $stripeSessionId, $actor)` requires `recover_checkout` permission and exact persisted operation-key, account, mode, order, revision, amount, and currency proof. A session lacking that proof cannot be adopted; investigate it manually before trying another payment.

## Development and verification

Refund creation writes the persisted operation key into Stripe `payment_operation_key` metadata. Reconciliation adopts a lost create response into the original refund only when that exact key and the account, mode, payment, currency, amount, reason, and unique refund ID agree. Repeated reconciliation is safe, and a late create response cannot overwrite a newer reconciled status. A refund lacking trustworthy operation metadata is never matched by amount; its unknown reservation stays blocked for investigation.

After an asynchronous Checkout failure, reconcile the attempt and call `StartCheckoutAction` again. It checks fresh Session and PaymentIntent facts before releasing the old reservation. An open Session is expired with a persisted idempotency key and verified again; timeouts retain the reservation, and reconciliation allows a retry with that same expiration key. A closed (`complete` or `expired`), unpaid Session is releasable only with a correlated PaymentIntent in `requires_payment_method` or `canceled`, zero captured/refunded/capturable amounts (an expired Session without a PaymentIntent is also safe). Processing, authorization, success, unknown facts, or concurrent local changes remain blocked. No Checkout-owned PaymentIntent cancellation is attempted. Treating a completed unpaid Checkout with a failed PaymentIntent as unable to collect again is an inference from Stripe's [Session lifecycle and direct-confirmation restriction](https://docs.stripe.com/api/checkout/sessions/object) and [cancellation restrictions](https://docs.stripe.com/api/payment_intents/cancel); verify delayed methods in your Stripe test account before live use.

In Stripe **test mode**, exercise automatic Checkout, manual authorization with partial final capture, cancellation, delayed payment success/failure, full and partial refunds, webhook retries, and reconciliation before enabling live mode. Run `nvl:payments:doctor --strict` during deployment. Package tests use a fake gateway, so they cannot validate a real account's webhook selection, capture method support, or Stripe credentials. From this workbench, run `php tools/run-package-tests.php payments` and `php tools/run-package-quality.php payments --format=json`.

## License

MIT. See [LICENSE](LICENSE) and [SECURITY.md](SECURITY.md).

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine the read-only checks from loaded NVL package providers. Errors fail the gate, and strict mode also fails warnings. This package's existing Doctor command remains available and uses the same package-owned inspection service.

## Next major: isolated schema identities

Use `payments.tables.<logical-key>` for every table and `payments.connection` for its database connection. Null connection inherits `nvl-core.connection`, then Laravel's default. Tables are resolved at runtime by the package table definition helper.

| Logical key | New default | Previous name |
| --- | --- | --- |
| `attempts` | `nvl_payments_attempts` | `nvl_payments_attempts` |
| `operations` | `nvl_payments_operations` | `nvl_payments_operations` |
| `refunds` | `nvl_payments_refunds` | `nvl_payments_refunds` |
| `webhook_events` | `nvl_payments_webhook_events` | `nvl_payments_webhook_events` |

Migration filenames contain `nvl_payments_`. Existing installations must complete the upgrade in `UPGRADING.md` before running new migrations. A pending creator rejects an existing target before any migration in the batch runs; legacy storage with old history needs an ownership decision.
