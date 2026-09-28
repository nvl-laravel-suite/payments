<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Payments\Http\Controllers\PaymentsWebhookController;
use Nvl\Payments\Http\Middleware\VerifyPaymentsWebhookSignature;

Route::post('/nvl/payments/stripe/webhook', PaymentsWebhookController::class)
    ->middleware(VerifyPaymentsWebhookSignature::class)
    ->name('nvl.payments.webhook');
