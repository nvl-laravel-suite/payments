<?php

declare(strict_types=1);

namespace Nvl\Payments\Exceptions;

use DomainException;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use Throwable;

/** Expected Payments domain, configuration and infrastructure failures.
 * @api
 */
class PaymentsException extends DomainException implements RespondableException
{
    use InteractsWithPackageFailure;

    private ?ExceptionResponse $failureResponse = null;

    /**
     * Preserve diagnostic copy and a previous cause independently of public copy.
     *
     * @param  array<string, mixed>  $publicContext
     */
    public static function because(PaymentsResponseCode $code, string $diagnosticMessage, array $publicContext = [], ?Throwable $previous = null): self
    {
        $exception = new self($diagnosticMessage, previous: $previous);
        $status = match ($code) {
            PaymentsResponseCode::FeatureDisabled => 404,
            PaymentsResponseCode::TenantInactive, PaymentsResponseCode::CheckoutConflict, PaymentsResponseCode::SubscriptionConflict,
            PaymentsResponseCode::ProviderIdentityMismatch, PaymentsResponseCode::OperationConflict, PaymentsResponseCode::PaymentStateInvalid,
            PaymentsResponseCode::ReconciliationRequired => 409,
            PaymentsResponseCode::InvalidConfiguration, PaymentsResponseCode::StorageUnavailable => 500,
            default => 422,
        };
        $exception->failureResponse = new ExceptionResponse('payments', $code, $status, $publicContext);

        return $exception;
    }

    /** Resolve explicitly safe metadata for this failure. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return $this->failureResponse ?? new ExceptionResponse('payments', PaymentsResponseCode::OperationFailed);
    }
}
