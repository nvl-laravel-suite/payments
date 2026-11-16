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
        Schema::connection($this->getConnection())->table(PaymentsTables::get(PaymentsTables::Attempts), function (Blueprint $table): void {
            $table->json('embedded_methods')->nullable();
            $table->string('exception_reason')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedSmallInteger('card_expiry_month')->nullable();
            $table->unsignedSmallInteger('card_expiry_year')->nullable();
            $table->string('card_wallet')->nullable();
        });
    }

    public function down(): void
    {
        if (config('nvl-payments.migrations.allow_destructive_rollback') !== true) {
            throw new LogicException('Payments financial rollback requires nvl-payments.migrations.allow_destructive_rollback.');
        }

        Schema::connection($this->getConnection())->table(PaymentsTables::get(PaymentsTables::Attempts), function (Blueprint $table): void {
            $table->dropColumn(['embedded_methods', 'exception_reason', 'card_brand', 'card_last_four', 'card_expiry_month', 'card_expiry_year', 'card_wallet']);
        });
    }
};
