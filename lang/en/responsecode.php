<?php

declare(strict_types=1);

return [
    'binding_required' => 'This capability requires an application adapter.',
    'operation_failed' => 'The operation could not be completed.',
    'feature_disabled' => 'This capability is unavailable.',
    'tenant_inactive' => 'The tenant is inactive.',
    'checkout_conflict' => 'An existing checkout prevents this operation.',
    'subscription_conflict' => 'The subscription state prevents this operation.',
    'provider_identity_mismatch' => 'The provider response does not match the requested operation.',
    'provider_payload_invalid' => 'The provider response or requested operation is invalid.',
    'operation_conflict' => 'The operation conflicts with the current state.',
    'payment_state_invalid' => 'The payment state prevents this operation.',
    'refund_balance_exceeded' => 'The refund exceeds the available captured balance.',
    'reconciliation_required' => 'Refresh the payment state before retrying.',
    'invalid_configuration' => 'This capability is not configured correctly.',
    'storage_unavailable' => 'Storage is unavailable for this operation.',
];
