# Public API: nvl/payments

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Payments\Contracts\ExistingPaymentOwnership`
- `Nvl\Payments\Contracts\PaymentCustomerGateway`
- `Nvl\Payments\Contracts\PaymentGateway`
- `Nvl\Payments\Contracts\PaymentIntentGateway`
- `Nvl\Payments\Contracts\PaymentLedgerProjector`
- `Nvl\Payments\Contracts\PaymentManagementAccess`
- `Nvl\Payments\Contracts\PaymentOrderProvider`
- `Nvl\Payments\Contracts\PaymentOwnerWorklist`
- `Nvl\Payments\Contracts\PaymentRefundLookup`
- `Nvl\Payments\Database\Factories\PaymentAttemptFactory`
- `Nvl\Payments\Database\Factories\PaymentOperationFactory`
- `Nvl\Payments\Database\Factories\PaymentRefundFactory`
- `Nvl\Payments\Database\Factories\PaymentWebhookEventFactory`
- `Nvl\Payments\Enums\PaymentsResponseCode`
- `Nvl\Payments\Events\PaymentStateChanged`
- `Nvl\Payments\Exceptions\PaymentsException`
- `Nvl\Payments\Exceptions\ProviderRefused`
- `Nvl\Payments\Exceptions\ProviderUnavailable`
- `Nvl\Payments\Http\RequirePaymentWebhookSecret`
- `Nvl\Payments\Ledger\PaymentCanceled`
- `Nvl\Payments\Ledger\PaymentExceptionRaised`
- `Nvl\Payments\Ledger\PaymentFailed`
- `Nvl\Payments\Ledger\PaymentLedgerFact`
- `Nvl\Payments\Ledger\PaymentSucceeded`
- `Nvl\Payments\Ledger\RefundCanceled`
- `Nvl\Payments\Ledger\RefundFailed`
- `Nvl\Payments\Ledger\RefundPending`
- `Nvl\Payments\Ledger\RefundSucceeded`
- `Nvl\Payments\Models\PaymentAttempt`
- `Nvl\Payments\Models\PaymentOperation`
- `Nvl\Payments\Models\PaymentRefund`
- `Nvl\Payments\Models\PaymentWebhookEvent`
- `Nvl\Payments\Services\EmbeddedPayments`
- `Nvl\Payments\Services\PaymentAuthorizations`
- `Nvl\Payments\Services\PaymentCheckout`
- `Nvl\Payments\Services\PaymentLedgerDelivery`
- `Nvl\Payments\Services\PaymentReadService`
- `Nvl\Payments\Services\PaymentReconciliation`
- `Nvl\Payments\Services\PaymentRefunds`
- `Nvl\Payments\Services\PaymentWebhooks`
- `Nvl\Payments\Services\PaymentsAvailability`
- `Nvl\Payments\Services\PaymentsModelRegistry`
- `Nvl\Payments\Testing\FakePaymentGateway`
- `Nvl\Payments\Testing\PaymentLedgerContractKit`
- `Nvl\Payments\ValueObjects\OrderPaymentSnapshot`

## Nvl\Payments\Contracts\ExistingPaymentOwnership

```text
interface Nvl\Payments\Contracts\ExistingPaymentOwnership
public abstract function assertOwned(Nvl\Payments\ValueObjects\OrderPaymentSnapshot $order, array $payment): void
@param StripePaymentState $payment
resolved @param array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string} $payment
```

## Nvl\Payments\Contracts\PaymentCustomerGateway

```text
interface Nvl\Payments\Contracts\PaymentCustomerGateway
public abstract function createCustomer(string $idempotencyKey): string
```

## Nvl\Payments\Contracts\PaymentGateway

```text
interface Nvl\Payments\Contracts\PaymentGateway
public abstract function cancel(string $paymentIntentId, string $idempotencyKey): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public abstract function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public abstract function checkout(string $sessionId): array
@return StripeCheckoutState
resolved @return array{sessionId: string, paymentIntentId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, paymentStatus: string, orderReference: ?string, orderRevision: ?string, expiresAt: Carbon\CarbonImmutable, operationKey: ?string, url: ?string}
public abstract function createCheckout(Nvl\Payments\ValueObjects\OrderPaymentSnapshot $order, string $captureMethod, string $successUrl, string $cancelUrl, Carbon\CarbonImmutable $expiresAt, string $idempotencyKey): array
@return HostedCheckout
resolved @return array{sessionId: string, url: string, expiresAt: Carbon\CarbonImmutable}
public abstract function expireCheckout(string $sessionId, string $idempotencyKey): void
public abstract function payment(string $paymentReference): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public abstract function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): array
@return StripeRefundState
resolved @return array{refundId: string, paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, reason: ?string, operationKey: ?string, providerOccurredAt?: ?int}
public abstract function refunds(string $paymentId): array
@return list<StripeRefundState>
resolved @return list<array{refundId: string, paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, reason: ?string, operationKey: ?string, providerOccurredAt?: ?int}>
public abstract function resolveExisting(string $reference): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
```

## Nvl\Payments\Contracts\PaymentIntentGateway

```text
interface Nvl\Payments\Contracts\PaymentIntentGateway extends Nvl\Payments\Contracts\PaymentGateway
public abstract function cancel(string $paymentIntentId, string $idempotencyKey): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public abstract function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public abstract function checkout(string $sessionId): array
@return StripeCheckoutState
resolved @return array{sessionId: string, paymentIntentId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, paymentStatus: string, orderReference: ?string, orderRevision: ?string, expiresAt: Carbon\CarbonImmutable, operationKey: ?string, url: ?string}
public abstract function createCheckout(Nvl\Payments\ValueObjects\OrderPaymentSnapshot $order, string $captureMethod, string $successUrl, string $cancelUrl, Carbon\CarbonImmutable $expiresAt, string $idempotencyKey): array
@return HostedCheckout
resolved @return array{sessionId: string, url: string, expiresAt: Carbon\CarbonImmutable}
public abstract function createIntent(Nvl\Payments\ValueObjects\OrderPaymentSnapshot $order, string $captureMethod, array $allowedMethods, ?string $customerId, string $idempotencyKey): array
@param list<string> $allowedMethods
@return StripeIntentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public abstract function expireCheckout(string $sessionId, string $idempotencyKey): void
public abstract function intent(string $intentId): array
@return StripeIntentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public abstract function payment(string $paymentReference): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public abstract function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): array
@return StripeRefundState
resolved @return array{refundId: string, paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, reason: ?string, operationKey: ?string, providerOccurredAt?: ?int}
public abstract function refunds(string $paymentId): array
@return list<StripeRefundState>
resolved @return list<array{refundId: string, paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, reason: ?string, operationKey: ?string, providerOccurredAt?: ?int}>
public abstract function resolveExisting(string $reference): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
```

## Nvl\Payments\Contracts\PaymentLedgerProjector

```text
interface Nvl\Payments\Contracts\PaymentLedgerProjector
public abstract function project(Nvl\Payments\Ledger\PaymentLedgerFact $fact): void
```

## Nvl\Payments\Contracts\PaymentManagementAccess

```text
interface Nvl\Payments\Contracts\PaymentManagementAccess
public abstract function assertCanManage(Illuminate\Contracts\Auth\Authenticatable $actor, string $operation, Nvl\Payments\ValueObjects\OrderPaymentSnapshot $order): void
```

## Nvl\Payments\Contracts\PaymentOrderProvider

```text
interface Nvl\Payments\Contracts\PaymentOrderProvider
public abstract function resolve(string $orderReference): Nvl\Payments\ValueObjects\OrderPaymentSnapshot
```

## Nvl\Payments\Contracts\PaymentOwnerWorklist

```text
interface Nvl\Payments\Contracts\PaymentOwnerWorklist
public abstract function owners(?string $attemptId, int $limit): iterable
@return iterable<OwnerReference>
resolved @return iterable<Nvl\Support\Ownership\ValueObjects\OwnerReference>
```

## Nvl\Payments\Contracts\PaymentRefundLookup

```text
interface Nvl\Payments\Contracts\PaymentRefundLookup
public abstract function readRefund(string $refundId): array
@return StripeRefundState
resolved @return array{refundId: string, paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, reason: ?string, operationKey: ?string, providerOccurredAt?: ?int}
```

## Nvl\Payments\Database\Factories\PaymentAttemptFactory

```text
final class Nvl\Payments\Database\Factories\PaymentAttemptFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<PaymentAttempt>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Payments\Models\PaymentAttempt>
public function definition(): array
@return array<model-property<PaymentAttempt>, mixed>
resolved @return array<model-property<Nvl\Payments\Models\PaymentAttempt>, mixed>
public function modelName(): string
@return class-string<PaymentAttempt>
resolved @return class-string<Nvl\Payments\Models\PaymentAttempt>
```

## Nvl\Payments\Database\Factories\PaymentOperationFactory

```text
final class Nvl\Payments\Database\Factories\PaymentOperationFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<PaymentOperation>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Payments\Models\PaymentOperation>
public function definition(): array
@return array<model-property<PaymentOperation>, mixed>
resolved @return array<model-property<Nvl\Payments\Models\PaymentOperation>, mixed>
public function forAttempt(Nvl\Payments\Models\PaymentAttempt $parent): static
```

## Nvl\Payments\Database\Factories\PaymentRefundFactory

```text
final class Nvl\Payments\Database\Factories\PaymentRefundFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<PaymentRefund>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Payments\Models\PaymentRefund>
public function definition(): array
@return array<model-property<PaymentRefund>, mixed>
resolved @return array<model-property<Nvl\Payments\Models\PaymentRefund>, mixed>
public function forOperation(Nvl\Payments\Models\PaymentOperation $parent): static
public function modelName(): string
@return class-string<PaymentRefund>
resolved @return class-string<Nvl\Payments\Models\PaymentRefund>
```

## Nvl\Payments\Database\Factories\PaymentWebhookEventFactory

```text
final class Nvl\Payments\Database\Factories\PaymentWebhookEventFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<PaymentWebhookEvent>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Payments\Models\PaymentWebhookEvent>
public function definition(): array
@return array<model-property<PaymentWebhookEvent>, mixed>
resolved @return array<model-property<Nvl\Payments\Models\PaymentWebhookEvent>, mixed>
```

## Nvl\Payments\Enums\PaymentsResponseCode

```text
enum Nvl\Payments\Enums\PaymentsResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Payments\Enums\PaymentsResponseCode BindingRequired=Nvl\Payments\Enums\PaymentsResponseCode::BindingRequired="binding_required"
public const Nvl\Payments\Enums\PaymentsResponseCode CheckoutConflict=Nvl\Payments\Enums\PaymentsResponseCode::CheckoutConflict="checkout_conflict"
public const Nvl\Payments\Enums\PaymentsResponseCode FeatureDisabled=Nvl\Payments\Enums\PaymentsResponseCode::FeatureDisabled="feature_disabled"
public const Nvl\Payments\Enums\PaymentsResponseCode InvalidConfiguration=Nvl\Payments\Enums\PaymentsResponseCode::InvalidConfiguration="invalid_configuration"
public const Nvl\Payments\Enums\PaymentsResponseCode OperationConflict=Nvl\Payments\Enums\PaymentsResponseCode::OperationConflict="operation_conflict"
public const Nvl\Payments\Enums\PaymentsResponseCode OperationFailed=Nvl\Payments\Enums\PaymentsResponseCode::OperationFailed="operation_failed"
public const Nvl\Payments\Enums\PaymentsResponseCode PaymentStateInvalid=Nvl\Payments\Enums\PaymentsResponseCode::PaymentStateInvalid="payment_state_invalid"
public const Nvl\Payments\Enums\PaymentsResponseCode ProviderIdentityMismatch=Nvl\Payments\Enums\PaymentsResponseCode::ProviderIdentityMismatch="provider_identity_mismatch"
public const Nvl\Payments\Enums\PaymentsResponseCode ProviderPayloadInvalid=Nvl\Payments\Enums\PaymentsResponseCode::ProviderPayloadInvalid="provider_payload_invalid"
public const Nvl\Payments\Enums\PaymentsResponseCode ReconciliationRequired=Nvl\Payments\Enums\PaymentsResponseCode::ReconciliationRequired="reconciliation_required"
public const Nvl\Payments\Enums\PaymentsResponseCode RefundBalanceExceeded=Nvl\Payments\Enums\PaymentsResponseCode::RefundBalanceExceeded="refund_balance_exceeded"
public const Nvl\Payments\Enums\PaymentsResponseCode StorageUnavailable=Nvl\Payments\Enums\PaymentsResponseCode::StorageUnavailable="storage_unavailable"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Payments\Events\PaymentStateChanged

```text
final readonly class Nvl\Payments\Events\PaymentStateChanged implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $orderReference, string $attemptId, string $oldState, string $newState, ?string $paymentIntentId, ?string $chargeId, ?string $checkoutSessionId, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly string $attemptId
public readonly ?string $chargeId
public readonly ?string $checkoutSessionId
public readonly string $newState
public readonly string $oldState
public readonly string $orderReference
public readonly ?string $paymentIntentId
public readonly int $schemaVersion
```

## Nvl\Payments\Exceptions\PaymentsException

```text
class Nvl\Payments\Exceptions\PaymentsException extends DomainException implements Nvl\Support\Contracts\RespondableException
public static function because(Nvl\Payments\Enums\PaymentsResponseCode $code, string $diagnosticMessage, array $publicContext=[], ?Throwable $previous=null): self
@param array<string, mixed> $publicContext
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Payments\Exceptions\ProviderRefused

```text
class Nvl\Payments\Exceptions\ProviderRefused extends Nvl\Payments\Exceptions\PaymentsException
public function __construct(?string $stripeCode, ?int $httpStatus=null): mixed
public static function because(Nvl\Payments\Enums\PaymentsResponseCode $code, string $diagnosticMessage, array $publicContext=[], ?Throwable $previous=null): self
@param array<string, mixed> $publicContext
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
public readonly ?int $httpStatus
public readonly ?string $stripeCode
```

## Nvl\Payments\Exceptions\ProviderUnavailable

```text
class Nvl\Payments\Exceptions\ProviderUnavailable extends Nvl\Payments\Exceptions\PaymentsException
public function __construct(?string $stripeCode=null, ?int $httpStatus=null): mixed
public static function because(Nvl\Payments\Enums\PaymentsResponseCode $code, string $diagnosticMessage, array $publicContext=[], ?Throwable $previous=null): self
@param array<string, mixed> $publicContext
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
public readonly ?int $httpStatus
public readonly ?string $stripeCode
```

## Nvl\Payments\Http\RequirePaymentWebhookSecret

```text
class Nvl\Payments\Http\RequirePaymentWebhookSecret
public function handle(Illuminate\Http\Request $request, Closure $next): Symfony\Component\HttpFoundation\Response
@param Closure(Request): Response $next
resolved @param Closure(Illuminate\Http\Request): Symfony\Component\HttpFoundation\Response $next
```

## Nvl\Payments\Ledger\PaymentCanceled

```text
readonly class Nvl\Payments\Ledger\PaymentCanceled implements Nvl\Payments\Ledger\PaymentLedgerFact
public function __construct(string $id, string $attemptReference, string $orderReference, int $amountMinor, string $currency, ?string $intentId, ?string $chargeId, bool $livemode, ?int $providerOccurredAt): mixed
public function id(): string
public readonly int $amountMinor
public readonly string $attemptReference
public readonly ?string $chargeId
public readonly string $currency
public readonly string $id
public readonly ?string $intentId
public readonly bool $livemode
public readonly string $orderReference
public readonly ?int $providerOccurredAt
```

## Nvl\Payments\Ledger\PaymentExceptionRaised

```text
readonly class Nvl\Payments\Ledger\PaymentExceptionRaised implements Nvl\Payments\Ledger\PaymentLedgerFact
public function __construct(string $id, string $attemptReference, string $orderReference, int $amountMinor, string $currency, ?string $intentId, ?string $chargeId, bool $livemode, ?int $providerOccurredAt, string $reason, int $expectedAmountMinor, string $expectedCurrency): mixed
public function id(): string
public readonly int $amountMinor
public readonly string $attemptReference
public readonly ?string $chargeId
public readonly string $currency
public readonly int $expectedAmountMinor
public readonly string $expectedCurrency
public readonly string $id
public readonly ?string $intentId
public readonly bool $livemode
public readonly string $orderReference
public readonly ?int $providerOccurredAt
public readonly string $reason
```

## Nvl\Payments\Ledger\PaymentFailed

```text
readonly class Nvl\Payments\Ledger\PaymentFailed implements Nvl\Payments\Ledger\PaymentLedgerFact
public function __construct(string $id, string $attemptReference, string $orderReference, int $amountMinor, string $currency, ?string $intentId, ?string $chargeId, bool $livemode, ?int $providerOccurredAt, ?string $failureCode): mixed
public function id(): string
public readonly int $amountMinor
public readonly string $attemptReference
public readonly ?string $chargeId
public readonly string $currency
public readonly ?string $failureCode
public readonly string $id
public readonly ?string $intentId
public readonly bool $livemode
public readonly string $orderReference
public readonly ?int $providerOccurredAt
```

## Nvl\Payments\Ledger\PaymentLedgerFact

```text
interface Nvl\Payments\Ledger\PaymentLedgerFact
public abstract function id(): string
```

## Nvl\Payments\Ledger\PaymentSucceeded

```text
readonly class Nvl\Payments\Ledger\PaymentSucceeded implements Nvl\Payments\Ledger\PaymentLedgerFact
public function __construct(string $id, string $attemptReference, string $orderReference, int $amountMinor, string $currency, ?string $intentId, ?string $chargeId, bool $livemode, ?int $providerOccurredAt, ?string $cardBrand, ?string $cardLastFour, ?int $cardExpiryMonth, ?int $cardExpiryYear, ?string $wallet): mixed
public function id(): string
public readonly int $amountMinor
public readonly string $attemptReference
public readonly ?string $cardBrand
public readonly ?int $cardExpiryMonth
public readonly ?int $cardExpiryYear
public readonly ?string $cardLastFour
public readonly ?string $chargeId
public readonly string $currency
public readonly string $id
public readonly ?string $intentId
public readonly bool $livemode
public readonly string $orderReference
public readonly ?int $providerOccurredAt
public readonly ?string $wallet
```

## Nvl\Payments\Ledger\RefundCanceled

```text
readonly class Nvl\Payments\Ledger\RefundCanceled implements Nvl\Payments\Ledger\PaymentLedgerFact
public function __construct(string $id, string $attemptReference, string $orderReference, int $amountMinor, string $currency, ?string $intentId, ?string $chargeId, bool $livemode, ?int $providerOccurredAt, string $refundId, string $operationId, ?string $reason, string $providerStatus): mixed
public function id(): string
public readonly int $amountMinor
public readonly string $attemptReference
public readonly ?string $chargeId
public readonly string $currency
public readonly string $id
public readonly ?string $intentId
public readonly bool $livemode
public readonly string $operationId
public readonly string $orderReference
public readonly ?int $providerOccurredAt
public readonly string $providerStatus
public readonly ?string $reason
public readonly string $refundId
```

## Nvl\Payments\Ledger\RefundFailed

```text
readonly class Nvl\Payments\Ledger\RefundFailed implements Nvl\Payments\Ledger\PaymentLedgerFact
public function __construct(string $id, string $attemptReference, string $orderReference, int $amountMinor, string $currency, ?string $intentId, ?string $chargeId, bool $livemode, ?int $providerOccurredAt, string $refundId, string $operationId, ?string $reason, string $providerStatus): mixed
public function id(): string
public readonly int $amountMinor
public readonly string $attemptReference
public readonly ?string $chargeId
public readonly string $currency
public readonly string $id
public readonly ?string $intentId
public readonly bool $livemode
public readonly string $operationId
public readonly string $orderReference
public readonly ?int $providerOccurredAt
public readonly string $providerStatus
public readonly ?string $reason
public readonly string $refundId
```

## Nvl\Payments\Ledger\RefundPending

```text
readonly class Nvl\Payments\Ledger\RefundPending implements Nvl\Payments\Ledger\PaymentLedgerFact
public function __construct(string $id, string $attemptReference, string $orderReference, int $amountMinor, string $currency, ?string $intentId, ?string $chargeId, bool $livemode, ?int $providerOccurredAt, string $refundId, string $operationId, ?string $reason, string $providerStatus): mixed
public function id(): string
public readonly int $amountMinor
public readonly string $attemptReference
public readonly ?string $chargeId
public readonly string $currency
public readonly string $id
public readonly ?string $intentId
public readonly bool $livemode
public readonly string $operationId
public readonly string $orderReference
public readonly ?int $providerOccurredAt
public readonly string $providerStatus
public readonly ?string $reason
public readonly string $refundId
```

## Nvl\Payments\Ledger\RefundSucceeded

```text
readonly class Nvl\Payments\Ledger\RefundSucceeded implements Nvl\Payments\Ledger\PaymentLedgerFact
public function __construct(string $id, string $attemptReference, string $orderReference, int $amountMinor, string $currency, ?string $intentId, ?string $chargeId, bool $livemode, ?int $providerOccurredAt, string $refundId, string $operationId, ?string $reason, string $providerStatus): mixed
public function id(): string
public readonly int $amountMinor
public readonly string $attemptReference
public readonly ?string $chargeId
public readonly string $currency
public readonly string $id
public readonly ?string $intentId
public readonly bool $livemode
public readonly string $operationId
public readonly string $orderReference
public readonly ?int $providerOccurredAt
public readonly string $providerStatus
public readonly ?string $reason
public readonly string $refundId
```

## Nvl\Payments\Models\PaymentAttempt

```text
class Nvl\Payments\Models\PaymentAttempt extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Payments\Definitions\Tables\PaymentsTables::Attempts
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getForeignKey(): string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Payments\Models\PaymentAttempt>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property int $amount_minor
@property (string | null) $capture_method
@property int $captured_amount_minor
@property (string | null) $card_brand
@property (int | null) $card_expiry_month
@property (int | null) $card_expiry_year
@property (string | null) $card_last_four
@property (string | null) $card_wallet
@property (string | null) $checkout_url
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property string $currency
@property (string | null) $embedded_customer
@property (list<string> | null) $embedded_methods
@property (string | null) $exception_reason
@property (Carbon | null) $expires_at
resolved @property (Illuminate\Support\Carbon | null) $expires_at
@property string $id
@property (Carbon | null) $last_reconcile_attempt_at
resolved @property (Illuminate\Support\Carbon | null) $last_reconcile_attempt_at
@property (Carbon | null) $last_synced_at
resolved @property (Illuminate\Support\Carbon | null) $last_synced_at
@property string $order_reference
@property string $order_revision
@property string $origin
@property (string | null) $owner_reference
@property string $owner_scope_key
@property int $refunded_amount_minor
@property (string | null) $reservation_key
@property string $state
@property (string | null) $stripe_account_id
@property (string | null) $stripe_charge_id
@property (string | null) $stripe_checkout_session_id
@property (bool | null) $stripe_livemode
@property (string | null) $stripe_payment_intent_id
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Payments\Models\PaymentOperation

```text
class Nvl\Payments\Models\PaymentOperation extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Payments\Definitions\Tables\PaymentsTables::Operations
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Payments\Models\PaymentOperation>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property (string | null) $actor_reference
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property string $id
@property string $idempotency_key
@property string $input_fingerprint
@property string $order_reference
@property (string | null) $owner_reference
@property string $owner_scope_key
@property (string | null) $payment_attempt_id
@property (Carbon | null) $resolved_at
resolved @property (Illuminate\Support\Carbon | null) $resolved_at
@property string $status
@property (string | null) $stripe_request_reference
@property (string | null) $stripe_result_reference
@property string $type
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Payments\Models\PaymentRefund

```text
class Nvl\Payments\Models\PaymentRefund extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Payments\Definitions\Tables\PaymentsTables::Refunds
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getForeignKey(): string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Payments\Models\PaymentRefund>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function paymentAttempt(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<PaymentAttempt, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Payments\Models\PaymentAttempt, $this>
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property int $amount_minor
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property string $currency
@property string $id
@property (string | null) $internal_note
@property (Carbon | null) $last_synced_at
resolved @property (Illuminate\Support\Carbon | null) $last_synced_at
@property int $ledger_transition
@property (string | null) $owner_reference
@property string $owner_scope_key
@property string $payment_attempt_id
@property string $payment_operation_id
@property (string | null) $reason
@property string $status
@property (string | null) $stripe_reason
@property (string | null) $stripe_refund_id
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Payments\Models\PaymentWebhookEvent

```text
class Nvl\Payments\Models\PaymentWebhookEvent extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Payments\Definitions\Tables\PaymentsTables::WebhookEvents
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Payments\Models\PaymentWebhookEvent>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property string $id
@property (string | null) $outcome
@property (string | null) $owner_reference
@property string $owner_scope_key
@property (Carbon | null) $processed_at
resolved @property (Illuminate\Support\Carbon | null) $processed_at
@property string $status
@property (string | null) $stripe_account_id
@property string $stripe_event_id
@property bool $stripe_livemode
@property (string | null) $stripe_object_id
@property string $type
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Payments\Services\EmbeddedPayments

```text
class Nvl\Payments\Services\EmbeddedPayments
public function __construct(Nvl\Payments\Contracts\PaymentOrderProvider $orders, Nvl\Payments\Contracts\PaymentManagementAccess $access, Nvl\Payments\Contracts\PaymentIntentGateway $gateway, Nvl\Payments\Services\PaymentOperationJournal $journal, Nvl\Payments\Services\PaymentOrderOwnership $scope, Nvl\Payments\Services\PaymentStateSyncer $syncer, Nvl\Payments\Services\PaymentReconciliation $reconciliation, ?Nvl\Payments\Services\PaymentCustomers $customers=null): mixed
public function close(string $attemptReference, Illuminate\Contracts\Auth\Authenticatable $actor): bool
public function recover(string $attemptReference, string $intentId, Illuminate\Contracts\Auth\Authenticatable $actor): Nvl\Payments\Models\PaymentAttempt
public function settle(string $attemptReference, Illuminate\Contracts\Auth\Authenticatable $actor): Nvl\Payments\Models\PaymentAttempt
public function start(string $orderReference, string $attemptReference, Illuminate\Contracts\Auth\Authenticatable $actor, ?string $hostCustomerReference=null): array
@return EmbeddedCheckout
resolved @return array{intentId: string, clientSecret: string, publishableKey: string, status: string, expiresAt: Carbon\CarbonImmutable}
```

## Nvl\Payments\Services\PaymentAuthorizations

```text
class Nvl\Payments\Services\PaymentAuthorizations
public function __construct(Nvl\Payments\Contracts\PaymentOrderProvider $orders, Nvl\Payments\Contracts\PaymentManagementAccess $access, Nvl\Payments\Contracts\PaymentGateway $gateway, Nvl\Payments\Services\PaymentOperationJournal $journal, Nvl\Payments\Services\PaymentStateSyncer $syncer, Nvl\Payments\Services\PaymentOrderOwnership $scope): mixed
public function cancel(Nvl\Payments\Models\PaymentAttempt|string $attempt, string $operationId, Illuminate\Contracts\Auth\Authenticatable $actor): Nvl\Payments\Models\PaymentAttempt
public function capture(Nvl\Payments\Models\PaymentAttempt|string $attempt, int $amountMinor, string $operationId, Illuminate\Contracts\Auth\Authenticatable $actor): Nvl\Payments\Models\PaymentAttempt
```

## Nvl\Payments\Services\PaymentCheckout

```text
class Nvl\Payments\Services\PaymentCheckout
public function __construct(Nvl\Payments\Contracts\PaymentOrderProvider $orders, Nvl\Payments\Contracts\PaymentManagementAccess $access, Nvl\Payments\Contracts\ExistingPaymentOwnership $ownership, Nvl\Payments\Contracts\PaymentGateway $gateway, Nvl\Payments\Services\PaymentOperationJournal $journal, Nvl\Payments\Services\PaymentStateSyncer $syncer, Nvl\Payments\Services\PaymentReconciliation $reconciler, Nvl\Payments\Services\PaymentOrderOwnership $scope, ?Nvl\Payments\Services\PaymentLedgerOutbox $outbox=null): mixed
public function attach(string $orderReference, string $stripeReference, string $operationId, Illuminate\Contracts\Auth\Authenticatable $actor): Nvl\Payments\Models\PaymentAttempt
public function recover(Nvl\Payments\Models\PaymentAttempt|string $attempt, string $stripeSessionId, Illuminate\Contracts\Auth\Authenticatable $actor): Nvl\Payments\Models\PaymentAttempt
public function start(string $orderReference, Illuminate\Contracts\Auth\Authenticatable $actor, string $successUrl, string $cancelUrl): array
@return HostedCheckout
resolved @return array{sessionId: string, url: string, expiresAt: Carbon\CarbonImmutable}
```

## Nvl\Payments\Services\PaymentLedgerDelivery

```text
class Nvl\Payments\Services\PaymentLedgerDelivery
public function deliver(string $id, bool $replay=false): bool
public function retry(int $limit=100): int
```

## Nvl\Payments\Services\PaymentReadService

```text
class Nvl\Payments\Services\PaymentReadService
public function __construct(Nvl\Payments\Contracts\PaymentOrderProvider $orders, Nvl\Payments\Contracts\PaymentManagementAccess $access, Nvl\Payments\Services\PaymentProjection $projection, Nvl\Payments\Services\PaymentOrderOwnership $scope): mixed
public function forOrder(string $orderReference, Illuminate\Contracts\Auth\Authenticatable $actor): array
@return array{orderReference: string, payments: list<PaymentSnapshot>, refunds: list<RefundSnapshot>}
resolved @return array{orderReference: string, payments: list<array{attemptId: string, orderReference: string, orderRevision: string, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, currency: string, status: string, exceptionReason: ?string, origin: string, checkoutSessionId: ?string, paymentIntentId: ?string, chargeId: ?string, cardBrand: ?string, cardLastFour: ?string, cardExpiryMonth: ?int, cardExpiryYear: ?int, wallet: ?string, accountId: ?string, livemode: ?bool, captureMethod: ?string, expiresAt: ?Carbon\CarbonImmutable, lastSyncedAt: ?Carbon\CarbonImmutable, reservedRefundAmountMinor: int, refundableAmountMinor: int}>, refunds: list<array{refundId: string, paymentAttemptId: string, operationId: string, amountMinor: int, currency: string, reason: ?string, status: string, stripeRefundId: ?string, lastSyncedAt: ?Carbon\CarbonImmutable}>}
```

## Nvl\Payments\Services\PaymentReconciliation

```text
class Nvl\Payments\Services\PaymentReconciliation
public function __construct(Nvl\Payments\Contracts\PaymentGateway $gateway, Nvl\Payments\Services\PaymentStateSyncer $syncer, Nvl\Payments\Services\PaymentOperationJournal $journal, Nvl\Payments\Services\RefundBalance $balance, Nvl\Payments\Ownership\PaymentsIntegrityGuard $integrity, Nvl\Payments\Services\PaymentRefundWriter $refundWriter, Nvl\Payments\Contracts\PaymentOrderProvider $orders, Nvl\Payments\Contracts\PaymentManagementAccess $access, Nvl\Payments\Services\PaymentOrderOwnership $scope, ?Nvl\Support\Events\DomainEventDispatcher $domainEvents=null, ?Nvl\Payments\Services\PaymentLedgerOutbox $outbox=null): mixed
public function reconcile(Nvl\Payments\Models\PaymentAttempt|string $attempt, ?string $recoverySessionId=null): Nvl\Payments\Models\PaymentAttempt
public function resolveException(Nvl\Payments\Models\PaymentAttempt|string $attempt, string $operationId, Illuminate\Contracts\Auth\Authenticatable $actor): Nvl\Payments\Models\PaymentAttempt
```

## Nvl\Payments\Services\PaymentRefunds

```text
class Nvl\Payments\Services\PaymentRefunds
public function __construct(Nvl\Payments\Contracts\PaymentOrderProvider $orders, Nvl\Payments\Contracts\PaymentManagementAccess $access, Nvl\Payments\Contracts\PaymentGateway $gateway, Nvl\Payments\Services\PaymentOperationJournal $journal, Nvl\Payments\Services\PaymentStateSyncer $syncer, Nvl\Payments\Services\RefundBalance $balance, Nvl\Payments\Services\PaymentOrderOwnership $scope, Nvl\Payments\Services\PaymentRefundWriter $refundWriter, ?Nvl\Payments\Services\PaymentLedgerOutbox $outbox=null): mixed
public function refund(Nvl\Payments\Models\PaymentAttempt|string $attempt, int $amountMinor, string $reason, ?string $note, string $operationId, Illuminate\Contracts\Auth\Authenticatable $actor): Nvl\Payments\Models\PaymentRefund
```

## Nvl\Payments\Services\PaymentWebhooks

```text
class Nvl\Payments\Services\PaymentWebhooks
public function __construct(Nvl\Payments\Contracts\PaymentGateway $gateway, Nvl\Payments\Services\PaymentStateSyncer $syncer, Nvl\Payments\Ownership\PaymentsProviderAdmission $ownership, ?Nvl\Payments\Services\PaymentReconciliation $reconciliation=null, ?Nvl\Payments\Services\PaymentWebhookInbox $inbox=null): mixed
public function enqueue(#[SensitiveParameter()] string $payload, #[SensitiveParameter()] string $signature): void
public static function eventTypes(): array
@return list<string>
public function process(#[SensitiveParameter()] string $payload, #[SensitiveParameter()] string $signature): void
```

## Nvl\Payments\Services\PaymentsAvailability

```text
class Nvl\Payments\Services\PaymentsAvailability
public function acceptsPayments(): bool
```

## Nvl\Payments\Services\PaymentsModelRegistry

```text
class Nvl\Payments\Services\PaymentsModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function attemptModel(): string
@return class-string<PaymentAttempt>
resolved @return class-string<Nvl\Payments\Models\PaymentAttempt>
public function prepare(): void
public function refundModel(): string
@return class-string<PaymentRefund>
resolved @return class-string<Nvl\Payments\Models\PaymentRefund>
```

## Nvl\Payments\Testing\FakePaymentGateway

```text
class Nvl\Payments\Testing\FakePaymentGateway implements Nvl\Payments\Contracts\PaymentCustomerGateway, Nvl\Payments\Contracts\PaymentIntentGateway, Nvl\Payments\Contracts\PaymentRefundLookup
public function assertCalled(string $method, ?Closure $predicate=null, int $times=1): void
@param (Closure(FakeCall): bool | null) $predicate
resolved @param (Closure(Nvl\Support\Testing\FakeCall): bool | null) $predicate
public function calls(?string $method=null): array
@return list<FakeCall>
resolved @return list<Nvl\Support\Testing\FakeCall>
public function cancel(string $paymentIntentId, string $idempotencyKey): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public function capture(string $paymentIntentId, int $amountMinor, string $idempotencyKey): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public function checkout(string $sessionId): array
@return StripeCheckoutState
resolved @return array{sessionId: string, paymentIntentId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, paymentStatus: string, orderReference: ?string, orderRevision: ?string, expiresAt: Carbon\CarbonImmutable, operationKey: ?string, url: ?string}
public function createCheckout(Nvl\Payments\ValueObjects\OrderPaymentSnapshot $order, string $captureMethod, string $successUrl, string $cancelUrl, Carbon\CarbonImmutable $expiresAt, string $idempotencyKey): array
@return HostedCheckout
resolved @return array{sessionId: string, url: string, expiresAt: Carbon\CarbonImmutable}
public function createCustomer(string $idempotencyKey): string
public function createIntent(Nvl\Payments\ValueObjects\OrderPaymentSnapshot $order, string $captureMethod, array $allowedMethods, ?string $customerId, string $idempotencyKey): array
@return StripeIntentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public function deliverWebhook(Nvl\Payments\Services\PaymentWebhooks $webhooks, array $event, #[SensitiveParameter()] ?string $secret=null, ?int $timestamp=null): void
@param array<string, mixed> $event
public function expireCheckout(string $sessionId, string $idempotencyKey): void
public static function fake(Illuminate\Contracts\Container\Container $container): self
public function intent(string $intentId): array
@return StripeIntentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public function payment(string $paymentReference): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public function readRefund(string $refundId): array
@return StripeRefundState
resolved @return array{refundId: string, paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, reason: ?string, operationKey: ?string, providerOccurredAt?: ?int}
public function refund(string $paymentId, int $amountMinor, string $reason, string $idempotencyKey): array
@return StripeRefundState
resolved @return array{refundId: string, paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, reason: ?string, operationKey: ?string, providerOccurredAt?: ?int}
public function refunds(string $paymentId): array
@return list<StripeRefundState>
resolved @return list<array{refundId: string, paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, currency: string, status: string, reason: ?string, operationKey: ?string, providerOccurredAt?: ?int}>
public function resolveExisting(string $reference): array
@return StripePaymentState
resolved @return array{paymentIntentId: ?string, chargeId: ?string, accountId: string, livemode: bool, amountMinor: int, capturedAmountMinor: int, refundedAmountMinor: int, releasedHoldRefundIds?: list<string>, currency: string, status: string, captureMethod: string, amountCapturableMinor: ?int, paymentMethodType: ?string, partialAuthorizationStatus: ?string, cardBrand?: ?string, cardLastFour?: ?string, cardExpiryMonth?: ?int, cardExpiryYear?: ?int, wallet?: ?string, providerOccurredAt?: ?int, failureCode?: ?string, failureReference?: ?string, clientSecret?: ?string, operationKey?: ?string}
public function willReturn(string $method, mixed $value): static
public function willThrow(string $method, Throwable $exception): static
```

## Nvl\Payments\Testing\PaymentLedgerContractKit

```text
class Nvl\Payments\Testing\PaymentLedgerContractKit
public function assertFailureIsRetryable(Nvl\Payments\Services\PaymentLedgerDelivery $delivery, string $id, Throwable $expected, Closure $allowDelivery): void
@param Closure(): void $allowDelivery
public function assertIdempotent(Nvl\Payments\Contracts\PaymentLedgerProjector $projector, Nvl\Payments\Ledger\PaymentLedgerFact $fact, Closure $countEffects): void
@param Closure(string): int $countEffects
public function assertReplay(Nvl\Payments\Services\PaymentLedgerDelivery $delivery, string $id, Closure $observedIds): void
@param Closure(): list<string> $observedIds
```

## Nvl\Payments\ValueObjects\OrderPaymentSnapshot

```text
final readonly class Nvl\Payments\ValueObjects\OrderPaymentSnapshot implements JsonSerializable
public function __construct(string $reference, string $revision, int $amountMinor, string $currency, string $label, ?string $email, bool $payable, ?Nvl\Support\Ownership\ValueObjects\OwnerReference $owner=null): mixed
public function jsonSerialize(): array
@return array{reference: string, revision: string, amountMinor: int, currency: string, label: string, email: ?string, payable: bool}
public readonly int $amountMinor
public readonly string $currency
public readonly ?string $email
public readonly string $label
public readonly ?Nvl\Support\Ownership\ValueObjects\OwnerReference $owner
public readonly bool $payable
public readonly string $reference
public readonly string $revision
```
