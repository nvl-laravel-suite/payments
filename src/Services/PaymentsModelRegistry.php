<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Nvl\Payments\Models\PaymentAttempt;
use Nvl\Payments\Models\PaymentRefund;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Freezes the configured financial roots while retaining native schema and source identity.
 *
 * @api
 */
final class PaymentsModelRegistry
{
    /** @var ConfiguredModelRegistration<PaymentAttempt> */
    private readonly ConfiguredModelRegistration $attempt;

    /** @var ConfiguredModelRegistration<PaymentRefund> */
    private readonly ConfiguredModelRegistration $refund;

    /** Retain deployment configuration and the canonical native identity registry. */
    public function __construct(Repository $configuration, OwnerRegistry $identities)
    {
        $this->attempt = new ConfiguredModelRegistration(
            $configuration, $identities, 'nvl-payments.models.attempt', PaymentAttempt::class, 'payments.attempts',
            static function (string $class): void {
                if ($class::ownershipDefinition()->strategy !== 'unscoped') {
                    throw new OwnershipConfigurationInvalid('Financial model declarations retain the package resource ownership strategy.');
                }
            },
        );
        $this->refund = new ConfiguredModelRegistration(
            $configuration, $identities, 'nvl-payments.models.refund', PaymentRefund::class, 'payments.refunds',
            static function (string $class): void {
                if ($class::ownershipDefinition()->strategy !== 'unscoped') {
                    throw new OwnershipConfigurationInvalid('Financial model declarations retain the package resource ownership strategy.');
                }
            },
        );
    }

    /** Freeze both root classes before compiling native resource and provider identities. @internal */
    public function prepare(): void
    {
        $this->attempt->prepare();
        $this->refund->prepare();
    }

    /** Resolve and validate the deployment's effective attempt model.
     * @return class-string<PaymentAttempt>
     */
    public function attemptModel(): string
    {
        $class = $this->attempt->modelClass();
        $this->assertStorage(new $class, new PaymentAttempt);

        return $class;
    }

    /** Resolve the configured root from native framework entry points.
     * @internal
     *
     * @return class-string<PaymentAttempt>
     */
    public static function attemptClass(): string
    {
        return Container::getInstance()->make(self::class)->attemptModel();
    }

    /** Construct the configured root on its package-owned source.
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function newAttempt(array $attributes = []): PaymentAttempt
    {
        $class = self::attemptClass();

        return new $class($attributes);
    }

    /** Build a canonical native root query.
     * @internal
     *
     * @return Builder<PaymentAttempt>
     */
    public static function attemptQuery(): Builder
    {
        return self::newAttempt()->newQuery();
    }

    /** Find a configured attempt, treating a malformed identifier as absent before it reaches the UUID key.
     * @internal
     */
    public static function findAttemptOrFail(string $id): PaymentAttempt
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(self::attemptClass(), [$id]);
        }

        return self::attemptQuery()->findOrFail($id);
    }

    /** Resolve and validate the deployment's effective refund model.
     * @return class-string<PaymentRefund>
     */
    public function refundModel(): string
    {
        $class = $this->refund->modelClass();
        $this->assertStorage(new $class, new PaymentRefund);

        return $class;
    }

    /** Resolve the configured root from native framework entry points.
     * @internal
     *
     * @return class-string<PaymentRefund>
     */
    public static function refundClass(): string
    {
        return Container::getInstance()->make(self::class)->refundModel();
    }

    /** Construct the configured root on its package-owned source.
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function newRefund(array $attributes = []): PaymentRefund
    {
        $class = self::refundClass();

        return new $class($attributes);
    }

    /** Build a canonical native root query.
     * @internal
     *
     * @return Builder<PaymentRefund>
     */
    public static function refundQuery(): Builder
    {
        return self::newRefund()->newQuery();
    }

    /** Reject split financial storage before technical children or effects can escape its transaction. */
    private function assertStorage(Model $configured, Model $base): void
    {
        if ($configured->getConnection() !== $base->getConnection() || $configured->getTable() !== $base->getTable()
            || $configured->getForeignKey() !== $base->getForeignKey()) {
            throw new OwnershipConfigurationInvalid('Configured financial roots must retain their package table, actual connection and native foreign key.');
        }
    }
}
