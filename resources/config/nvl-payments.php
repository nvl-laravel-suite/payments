<?php

declare(strict_types=1);

use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;

return ['models' => ['attempt' => PaymentAttempt::class, 'refund' => PaymentRefund::class], 'ownership' => ['scoped' => true],
    'enabled' => false,
    'connection' => null,
    // Choose vendor migrations or publish them into the host; never enable both modes.
    'migrations' => ['enabled' => false],
];
