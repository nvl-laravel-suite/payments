<?php

declare(strict_types=1);

namespace Nvl\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Payments\Models\PaymentAttempt;

/**
 * Builds PaymentAttempt fixture rows and their declared package parents.
 *
 * @extends Factory<PaymentAttempt>
 *
 * @api
 */
final class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<PaymentAttempt>, mixed>
     */
    public function definition(): array
    {
        return [
            'order_reference' => $this->faker->unique()->uuid(),
            'order_revision' => '1',
            'amount_minor' => 1000,
            'currency' => 'EUR',
            'origin' => 'checkout',
            'state' => 'reserved',
            'captured_amount_minor' => 0,
            'refunded_amount_minor' => 0,
        ];
    }
}
