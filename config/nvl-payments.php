<?php

declare(strict_types=1);

use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'models' => ['attempt' => PaymentAttempt::class, 'refund' => PaymentRefund::class],
    'ownership' => ['scoped' => true],
    'enabled' => false,
    'allow_live' => false,
    'connection' => null,
    // Choose vendor migrations or publish them into the host; never enable both modes.
    'migrations' => ['enabled' => false, 'allow_destructive_rollback' => false],
    'stripe' => [
        'secret' => env('NVL_PAYMENTS_STRIPE_SECRET'),
        'publishable_key' => env('NVL_PAYMENTS_STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('NVL_PAYMENTS_STRIPE_WEBHOOK_SECRET'),
        'account_id' => env('NVL_PAYMENTS_STRIPE_ACCOUNT_ID'),
        'livemode' => false,
    ],
    'allowed_currencies' => ['EUR'],
    'minimum_amounts' => ['EUR' => 50],
    'embedded' => [
        'capture_method' => 'automatic_async',
        'allowed_payment_method_types' => ['card'],
        'expires_in_minutes' => 30,
    ],
    'checkout' => [
        'return_hosts' => [],
        'expires_in_minutes' => 120,
        'capture_method' => 'automatic',
    ],
    'reconciliation' => ['batch_size' => 100, 'max_attempts' => 1000],
];
