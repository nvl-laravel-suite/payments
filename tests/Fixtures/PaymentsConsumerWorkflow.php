<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Nvl\Payments\Contracts\AttachExistingPaymentContract;
use Nvl\Payments\Contracts\CancelAuthorizationContract;
use Nvl\Payments\Contracts\CapturePaymentContract;
use Nvl\Payments\Contracts\PaymentReadContract;
use Nvl\Payments\Contracts\RecoverCheckoutContract;
use Nvl\Payments\Contracts\RefundPaymentContract;
use Nvl\Payments\Contracts\ResolvePaymentExceptionContract;
use Nvl\Payments\Contracts\StartCheckoutContract;
use Nvl\Payments\ValueObjects\HostedCheckout;
use Nvl\Payments\ValueObjects\OrderPaymentTimeline;
use Nvl\Payments\ValueObjects\PaymentSnapshot;
use Nvl\Payments\ValueObjects\RefundSnapshot;

/** Composes Payments contracts in an actual host-owned application service. */
final readonly class PaymentsConsumerWorkflow
{
    /** Receive the complete supported workflow boundaries through constructor injection. */
    public function __construct(
        private AttachExistingPaymentContract $attach,
        private CancelAuthorizationContract $cancel,
        private CapturePaymentContract $capture,
        private RecoverCheckoutContract $recover,
        private RefundPaymentContract $refund,
        private ResolvePaymentExceptionContract $accept,
        private StartCheckoutContract $checkout,
        private PaymentReadContract $read,
    ) {}

    /**
     * Build host decisions from the declared immutable result objects.
     *
     * @return array{attached: PaymentSnapshot, canceled: PaymentSnapshot, captured: PaymentSnapshot, recovered: PaymentSnapshot, accepted: PaymentSnapshot, refund: RefundSnapshot, checkout: HostedCheckout, timeline: OrderPaymentTimeline, url: string, refundable: int}
     */
    public function prepare(Authenticatable $actor): array
    {
        $checkout = $this->checkout->execute('order-host', $actor, 'https://app.example.test/success', 'https://app.example.test/cancel');
        $timeline = $this->read->forOrder('order-host', $actor);

        return [
            'attached' => $this->attach->execute('order-host', $actor, 'pi_host', 'operation-attach'),
            'canceled' => $this->cancel->execute('attempt-cancel', $actor, 'operation-cancel'),
            'captured' => $this->capture->execute('attempt-capture', $actor, 1200, 'operation-capture'),
            'recovered' => $this->recover->execute('attempt-recover', 'cs_recover', $actor),
            'accepted' => $this->accept->execute('attempt-exception', $actor, 'operation-exception'),
            'refund' => $this->refund->execute('attempt-host', $actor, 200, 'requested_by_customer', null, 'operation-refund'),
            'checkout' => $checkout,
            'timeline' => $timeline,
            'url' => $checkout->url,
            'refundable' => $timeline->payments[0]->refundableAmountMinor,
        ];
    }

    /** Identify the current dependency for discovery and late replacement checks. */
    public function dependency(string $contract): object
    {
        return match ($contract) {
            AttachExistingPaymentContract::class => $this->attach,
            CancelAuthorizationContract::class => $this->cancel,
            CapturePaymentContract::class => $this->capture,
            RecoverCheckoutContract::class => $this->recover,
            RefundPaymentContract::class => $this->refund,
            ResolvePaymentExceptionContract::class => $this->accept,
            StartCheckoutContract::class => $this->checkout,
            PaymentReadContract::class => $this->read,
            default => throw new InvalidArgumentException('Unknown Payments workflow contract.'),
        };
    }
}
