<?php

declare(strict_types=1);

namespace Nvl\Payments\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Payments\Contracts\CapturePaymentContract;
use Nvl\Payments\Services\AuthorizationOperations;
use Nvl\Payments\ValueObjects\PaymentSnapshot;

/** Coordinates a confirmed authorization operation through its durable shared boundary.
 * The explicit primitive signature is the host contract for internal attempt identifiers.
 *
 * @api
 */
final class CapturePaymentAction implements CapturePaymentContract
{
    /** Inject the shared authorization operation boundary. */
    public function __construct(private readonly AuthorizationOperations $operations) {}

    /** Perform the authorized operation once and return synchronized financial facts. */
    public function execute(string $attemptId, Authenticatable $actor, int $amountMinor, string $operationId): PaymentSnapshot
    {
        return $this->operations->execute('capture', $attemptId, $actor, $amountMinor, $operationId);
    }
}
