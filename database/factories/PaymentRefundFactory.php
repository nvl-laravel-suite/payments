<?php

declare(strict_types=1);

namespace Nvl\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentOperation;
use Nvl\Payments\Models\PaymentRefund;

/**
 * Builds PaymentRefund fixture rows and their declared package parents.
 *
 * @extends Factory<PaymentRefund>
 *
 * @api
 */
final class PaymentRefundFactory extends Factory
{
    protected $model = PaymentRefund::class;

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
        })->afterMaking(function (PaymentRefund $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('payment_operation_id') !== null) {
                $parent = PaymentOperation::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('payment_operation_id')));
                FactoryGuard::parent($parent, $model);
                if ($parent->payment_attempt_id !== $model->payment_attempt_id) {
                    throw new InvalidArgumentException('Refund fixtures require the operation attempt.');
                }
                $attempt = PaymentAttempt::query()->findOrFail($model->payment_attempt_id);
                FactoryGuard::parent($attempt, $model);
                if ($model->currency !== $attempt->currency || $model->amount_minor <= 0
                    || $model->amount_minor > $attempt->captured_amount_minor - $attempt->refunded_amount_minor) {
                    throw new InvalidArgumentException('Refund fixtures require matching currency and available captured funds.');
                }
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<PaymentRefund>, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_operation_id' => PaymentOperation::factory(),
            'payment_attempt_id' => fn (array $attributes): ?string => $attributes['payment_operation_id'] === null ? null : PaymentOperation::query()->findOrFail(FactoryGuard::identifier($attributes['payment_operation_id']))->payment_attempt_id,
            'amount_minor' => 1000,
            'currency' => 'EUR',
            'status' => 'pending',
        ];
    }

    /**
     * Associate an admitted persisted PaymentOperation parent.
     *
     * @api
     */
    public function forOperation(PaymentOperation $parent): static
    {
        FactoryGuard::parent($parent, new PaymentRefund);

        return $this->state([
            'payment_operation_id' => $parent->getKey(),
            'payment_attempt_id' => $parent->payment_attempt_id,
        ]);
    }
}
