<?php

declare(strict_types=1);

namespace Nvl\Payments\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Payments\Contracts\PaymentCustomerGateway;
use Nvl\Payments\Contracts\PaymentGateway;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Payments\Enums\PaymentsResponseCode;
use Nvl\Payments\Exceptions\PaymentsException;

/** @internal */
final readonly class PaymentCustomers
{
    public function __construct(private PaymentGateway $gateway) {}

    /** The caller supplies a trusted host reference only for a signed-in shopper. */
    public function resolve(string $reference): ?string
    {
        if (config('nvl-payments.customers.enabled') !== true) {
            return null;
        }
        if ($reference === '' || strlen($reference) > 255 || str_contains($reference, "\0")) {
            throw new InvalidArgumentException('A stable host customer reference is required.');
        }
        if (! $this->gateway instanceof PaymentCustomerGateway) {
            throw new \LogicException('The active gateway does not support Stripe customer creation.');
        }
        PaymentsAvailability::assertOutsideTransaction();
        $connection = PaymentsModelRegistry::newAttempt()->getConnection();
        $table = PaymentsTables::get(PaymentsTables::Customers);
        $record = $connection->table($table)->useWritePdo();
        $connection->table($table)->insertOrIgnore([
            'id' => (string) Str::uuid(), 'host_reference' => $reference, 'created_at' => now(),
            'stripe_account_id' => config('nvl-payments.stripe.account_id'), 'stripe_livemode' => config('nvl-payments.stripe.livemode'),
        ]);
        $customer = (clone $record)->where('host_reference', $reference)->sole();
        if ($customer->stripe_account_id !== config('nvl-payments.stripe.account_id') || (bool) $customer->stripe_livemode !== config('nvl-payments.stripe.livemode')) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderIdentityMismatch, 'Customer is linked to another Stripe account or mode.');
        }
        if (is_string($customer->stripe_customer_id)) {
            return $customer->stripe_customer_id;
        }
        if (! is_string($customer->created_at) || ! is_string($customer->id)) {
            throw new \UnexpectedValueException('Malformed Payments customer record.');
        }
        if (now()->diffInHours(CarbonImmutable::parse($customer->created_at), true) >= 23) {
            throw PaymentsException::because(PaymentsResponseCode::ReconciliationRequired, 'The Stripe customer creation outcome requires review.');
        }
        $id = $this->gateway->createCustomer('payments:customer:'.$customer->id);
        if (! str_starts_with($id, 'cus_')) {
            throw PaymentsException::because(PaymentsResponseCode::ProviderPayloadInvalid, 'Stripe returned an invalid customer.');
        }
        (clone $record)->where('id', $customer->id)->whereNull('stripe_customer_id')->update(['stripe_customer_id' => $id]);
        $stored = (clone $record)->where('id', $customer->id)->value('stripe_customer_id');
        if ($stored !== $id) {
            throw PaymentsException::because(PaymentsResponseCode::OperationConflict, 'Stripe customer creation returned a conflicting identity.');
        }

        return $id;
    }
}
