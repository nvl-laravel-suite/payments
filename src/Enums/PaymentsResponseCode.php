<?php

declare(strict_types=1);

namespace Nvl\Payments\Enums;

use Nvl\Support\Contracts\ResponseCode;

/** Stable public response codes for Payments.
 * @api
 */
enum PaymentsResponseCode: string implements ResponseCode
{
    case BindingRequired = 'binding_required';
    case OperationFailed = 'operation_failed';
    case FeatureDisabled = 'feature_disabled';
    case TenantInactive = 'tenant_inactive';
    case CheckoutConflict = 'checkout_conflict';
    case SubscriptionConflict = 'subscription_conflict';
    case ProviderIdentityMismatch = 'provider_identity_mismatch';
    case ProviderPayloadInvalid = 'provider_payload_invalid';
    case OperationConflict = 'operation_conflict';
    case PaymentStateInvalid = 'payment_state_invalid';
    case RefundBalanceExceeded = 'refund_balance_exceeded';
    case ReconciliationRequired = 'reconciliation_required';
    case InvalidConfiguration = 'invalid_configuration';
    case StorageUnavailable = 'storage_unavailable';
}
