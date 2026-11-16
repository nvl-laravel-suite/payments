<?php

declare(strict_types=1);

namespace Nvl\Payments\Testing;

use Closure;
use Nvl\Payments\Contracts\PaymentLedgerProjector;
use Nvl\Payments\Ledger\PaymentLedgerFact;
use Nvl\Payments\Services\PaymentLedgerDelivery;
use RuntimeException;
use Throwable;

/**
 * Contract assertions hosts can run against their real projector and an isolated test ledger.
 *
 * @api
 */
class PaymentLedgerContractKit
{
    /** @param Closure(string):int $countEffects */
    public function assertIdempotent(PaymentLedgerProjector $projector, PaymentLedgerFact $fact, Closure $countEffects): void
    {
        $before = $countEffects($fact->id());
        $projector->project($fact);
        $projector->project($fact);
        if ($countEffects($fact->id()) !== $before + 1) {
            throw new RuntimeException('A previously unseen ledger fact must create exactly one recorded effect when delivered twice.');
        }
    }

    /** @param Closure():list<string> $observedIds */
    public function assertReplay(PaymentLedgerDelivery $delivery, string $id, Closure $observedIds): void
    {
        $before = count($observedIds());
        if (! $delivery->deliver($id, replay: true) || ! $delivery->deliver($id, replay: true)
            || array_slice($observedIds(), $before) !== [$id, $id]) {
            throw new RuntimeException('Ledger replay must deliver the identical saved fact ID.');
        }
    }

    /** @param Closure():void $allowDelivery */
    public function assertFailureIsRetryable(PaymentLedgerDelivery $delivery, string $id, Throwable $expected, Closure $allowDelivery): void
    {
        try {
            $delivery->deliver($id);
        } catch (Throwable $observed) {
            if ($observed !== $expected) {
                throw $observed;
            }
            $allowDelivery();
            if ($delivery->deliver($id)) {
                return;
            }
        }

        throw new RuntimeException('The delivery must propagate the projector failure and preserve the pending fact.');
    }
}
