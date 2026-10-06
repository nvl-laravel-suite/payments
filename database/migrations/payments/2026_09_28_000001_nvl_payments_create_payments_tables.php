<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\Payments\Definitions\Tables\PaymentsTables;

/** Creates portable audit stores for order payment attempts and Stripe events. */
return new class extends Migration
{
    /** Use the configured Payments connection or Laravel's default connection. */
    public function getConnection(): ?string
    {
        $connection = config('nvl-payments.connection');

        return is_string($connection) ? $connection : null;
    }

    /** Create the four UUID-backed Payments tables and their identity constraints. */
    public function up(): void
    {
        $schema = $this->schema();

        $schema->create(PaymentsTables::get(PaymentsTables::Attempts), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_reference');
            $table->string('order_revision');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('origin');
            $table->string('state');
            $table->string('reservation_key')->nullable()->unique();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_charge_id')->nullable()->unique();
            $table->string('stripe_account_id')->nullable();
            $table->boolean('stripe_livemode')->nullable();
            $table->string('capture_method')->nullable();
            $table->unsignedBigInteger('captured_amount_minor')->default(0);
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_reconcile_attempt_at')->nullable();
            $table->timestamps();
            $table->index(['order_reference', 'created_at'], 'nvl_payments_attempt_order_idx');
            $table->index(['last_reconcile_attempt_at', 'id'], 'nvl_payments_attempt_reconcile_idx');
        });

        $schema->create(PaymentsTables::get(PaymentsTables::Operations), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_attempt_id')->nullable();
            $table->string('order_reference');
            $table->string('type');
            $table->string('actor_reference')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('input_fingerprint');
            $table->string('stripe_request_reference')->nullable();
            $table->string('stripe_result_reference')->nullable();
            $table->string('status');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['payment_attempt_id', 'created_at'], 'nvl_payments_operation_attempt_idx');
            $table->foreign('payment_attempt_id')->references('id')->on(PaymentsTables::get(PaymentsTables::Attempts))->restrictOnDelete();
        });

        $schema->create(PaymentsTables::get(PaymentsTables::Refunds), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_attempt_id');
            $table->uuid('payment_operation_id')->unique();
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('status');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->index(['payment_attempt_id', 'created_at'], 'nvl_payments_refund_attempt_idx');
            $table->foreign('payment_attempt_id')->references('id')->on(PaymentsTables::get(PaymentsTables::Attempts))->restrictOnDelete();
            $table->foreign('payment_operation_id')->references('id')->on(PaymentsTables::get(PaymentsTables::Operations))->restrictOnDelete();
        });

        $schema->create(PaymentsTables::get(PaymentsTables::WebhookEvents), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->string('status');
            $table->string('stripe_object_id')->nullable();
            $table->string('stripe_account_id')->nullable();
            $table->boolean('stripe_livemode');
            $table->string('outcome')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /** Remove Payments tables in reverse dependency order. */
    public function down(): void
    {
        $schema = $this->schema();
        $schema->dropIfExists(PaymentsTables::get(PaymentsTables::WebhookEvents));
        $schema->dropIfExists(PaymentsTables::get(PaymentsTables::Refunds));
        $schema->dropIfExists(PaymentsTables::get(PaymentsTables::Operations));
        $schema->dropIfExists(PaymentsTables::get(PaymentsTables::Attempts));
    }

    /** Resolve the configured schema builder. */
    private function schema(): Builder
    {
        return Schema::connection($this->getConnection());
    }
};
