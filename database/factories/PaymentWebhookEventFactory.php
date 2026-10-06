<?php

declare(strict_types=1);

namespace Nvl\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Payments\Models\PaymentWebhookEvent;

/**
 * Builds PaymentWebhookEvent fixture rows and their declared package parents.
 *
 * @extends Factory<PaymentWebhookEvent>
 *
 * @api
 */
final class PaymentWebhookEventFactory extends Factory
{
    protected $model = PaymentWebhookEvent::class;

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<PaymentWebhookEvent>, mixed>
     */
    public function definition(): array
    {
        return [
            'stripe_event_id' => 'evt_'.$this->faker->unique()->uuid(),
            'type' => 'checkout.session.completed',
            'status' => 'received',
            'stripe_livemode' => false,
        ];
    }
}
