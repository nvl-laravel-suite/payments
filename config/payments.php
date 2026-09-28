<?php

declare(strict_types=1);

return [
    'enabled' => false,
    'connection' => null,
    // Choose vendor migrations or publish them into the host; never enable both modes.
    'migrations' => ['enabled' => false],
    'stripe' => [
        'secret' => env('PAYMENTS_STRIPE_SECRET'),
        'webhook_secret' => env('PAYMENTS_STRIPE_WEBHOOK_SECRET'),
        'account_id' => env('PAYMENTS_STRIPE_ACCOUNT_ID'),
        'livemode' => false,
    ],
    'allowed_currencies' => [],
    'checkout' => [
        'return_hosts' => [],
        'expires_in_minutes' => 120,
        'capture_method' => 'automatic',
    ],
    'reconciliation' => ['batch_size' => 100, 'max_attempts' => 1000],
];
