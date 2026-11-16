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
        $schema->table(PaymentsTables::get(PaymentsTables::Refunds), function (Blueprint $table): void {
            $table->string('stripe_reason')->nullable();
            $table->unsignedInteger('ledger_transition')->default(0);
        });
        $schema->create(PaymentsTables::get(PaymentsTables::LedgerFacts), function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->char('id', 64)->unique();
            $table->uuid('attempt_id');
            $table->string('type');
            $table->json('facts');
            $table->timestamp('created_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_delivery_attempt_at')->nullable();
            $table->index(['delivered_at', 'sequence']);
            $table->index(['attempt_id', 'delivered_at', 'sequence'], 'nvl_payments_ledger_stream_index');
        });
    }

    public function down(): void
    {
        if (config('nvl-payments.migrations.allow_destructive_rollback') !== true) {
            throw new LogicException('Payments financial rollback requires nvl-payments.migrations.allow_destructive_rollback.');
        }

        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists(PaymentsTables::get(PaymentsTables::LedgerFacts));
        $schema->table(PaymentsTables::get(PaymentsTables::Refunds), function (Blueprint $table): void {
            $table->dropColumn(['stripe_reason', 'ledger_transition']);
        });
    }
};
