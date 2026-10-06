<?php

declare(strict_types=1);

namespace Nvl\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;

/**
 * Builds PaymentOperation fixture rows and their declared package parents.
 *
 * @extends Factory<PaymentOperation>
 *
 * @api
 */
final class PaymentOperationFactory extends Factory
{
    protected $model = PaymentOperation::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (PaymentOperation $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('payment_attempt_id') !== null) {
                $parent = PaymentAttempt::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('payment_attempt_id')));
                FactoryGuard::parent($parent, $model);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<PaymentOperation>, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_attempt_id' => PaymentAttempt::factory()->state(['state' => 'paid', 'captured_amount_minor' => 1000]),
            'order_reference' => fn (array $attributes): string => $attributes['payment_attempt_id'] === null ? 'fixture' : PaymentAttempt::query()->findOrFail(FactoryGuard::identifier($attributes['payment_attempt_id']))->order_reference,
            'type' => 'refund',
            'idempotency_key' => $this->faker->unique()->uuid(),
            'input_fingerprint' => hash('sha256', $this->faker->uuid()),
            'status' => 'pending',
        ];
    }

    /**
     * Associate an admitted persisted PaymentAttempt parent.
     *
     * @api
     */
    public function forAttempt(PaymentAttempt $parent): static
    {
        FactoryGuard::parent($parent, new PaymentOperation);

        return $this->state([
            'payment_attempt_id' => $parent->getKey(),
            'order_reference' => $parent->order_reference,
        ]);
    }
}
