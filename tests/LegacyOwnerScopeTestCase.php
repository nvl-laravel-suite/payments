<?php

declare(strict_types=1);

namespace Nvl\Payments\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Payments\Definitions\Tables\PaymentsTables;

/** Adopts real historical payment and operation identities without reconstructing external requests. */
abstract class LegacyOwnerScopeTestCase extends OwnerScopeTestCase
{
    public const string Attempt = '6d19a317-e28a-4cc9-8b4d-3ec786e9034e';

    public const string Operation = '5ce12b8c-de72-4a31-bbb1-415a0b2541f8';

    public const string Receipt = '701d567b-31fb-4342-ae76-dc2a6550ef27';

    public const string OperationKey = 'payments:checkout:5ce12b8c-de72-4a31-bbb1-415a0b2541f8';

    /** @return list<OwnershipMapping> */
    protected function adoptionMappings(): array
    {
        $now = CarbonImmutable::now();
        DB::table(PaymentsTables::get(PaymentsTables::Attempts))->insert([
            'id' => self::Attempt, 'order_reference' => 'old-order', 'order_revision' => 'v1', 'amount_minor' => 1000, 'currency' => 'EUR',
            'origin' => 'checkout', 'state' => 'reserved', 'reservation_key' => hash('sha256', 'old-order'),
            'stripe_checkout_session_id' => 'cs_old', 'stripe_payment_intent_id' => 'pi_old', 'stripe_account_id' => 'acct_native', 'stripe_livemode' => false,
            'capture_method' => 'automatic', 'captured_amount_minor' => 0, 'refunded_amount_minor' => 0,
            'expires_at' => $now->addHour(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table(PaymentsTables::get(PaymentsTables::Operations))->insert([
            'id' => self::Operation, 'payment_attempt_id' => self::Attempt, 'order_reference' => 'old-order', 'type' => 'checkout', 'actor_reference' => '1',
            'idempotency_key' => self::OperationKey, 'input_fingerprint' => hash('sha256', 'original immutable input'), 'status' => 'unknown', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table(PaymentsTables::get(PaymentsTables::WebhookEvents))->insert([
            'id' => self::Receipt, 'stripe_event_id' => 'evt_old', 'type' => 'payment_intent.succeeded', 'stripe_object_id' => 'pi_old',
            'stripe_account_id' => null, 'stripe_livemode' => false, 'status' => 'processed', 'outcome' => 'synchronized', 'processed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $owner = $this->app->make(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));

        return [new OwnershipMapping('payments.attempts', self::Attempt, $owner), new OwnershipMapping('payments.operations', self::Operation, $owner),
            new OwnershipMapping('payments.webhooks', self::Receipt, null)];
    }
}
