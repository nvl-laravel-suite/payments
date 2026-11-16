<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Payments\Definitions\Tables\PaymentsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return PackageStorage::connection('payments');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table(PaymentsTables::get(PaymentsTables::Attempts), function (Blueprint $table): void {
            $table->string('embedded_customer')->nullable();
        });
        $schema->create(PaymentsTables::get(PaymentsTables::Customers), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_reference')->unique();
            $table->string('stripe_account_id');
            $table->boolean('stripe_livemode');
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->timestamp('created_at');
        });
        $schema->create(PaymentsTables::get(PaymentsTables::WebhookInbox), function (Blueprint $table): void {
            $table->string('stripe_event_id')->primary();
            $table->string('type');
            $table->string('stripe_object_id');
            $table->string('stripe_account_id')->nullable();
            $table->boolean('stripe_livemode');
            $table->unsignedBigInteger('stripe_created_at')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('outcome')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->index(['status', 'attempts', 'updated_at'], 'nvl_payments_inbox_recovery_index');
        });
    }

    public function down(): void
    {
        if (config('nvl-payments.migrations.allow_destructive_rollback') !== true) {
            throw new LogicException('Payments financial rollback requires nvl-payments.migrations.allow_destructive_rollback.');
        }

        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists(PaymentsTables::get(PaymentsTables::WebhookInbox));
        $schema->dropIfExists(PaymentsTables::get(PaymentsTables::Customers));
        $schema->table(PaymentsTables::get(PaymentsTables::Attempts), function (Blueprint $table): void {
            $table->dropColumn('embedded_customer');
        });
    }
};
