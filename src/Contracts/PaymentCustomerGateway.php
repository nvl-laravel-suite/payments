<?php

declare(strict_types=1);

namespace Nvl\Payments\Contracts;

/**
 * Optional provider capability for creating a Stripe customer with a durable key.
 *
 * @api
 */
interface PaymentCustomerGateway
{
    public function createCustomer(string $idempotencyKey): string;
}
