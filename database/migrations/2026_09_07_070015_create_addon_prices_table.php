<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same immutable-versioned shape as plan_prices, for the same reason.
        Schema::create('addon_prices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('addon_id')->constrained()->restrictOnDelete();
            $table->string('billing_interval', 16); // month | year
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('dodo_product_id')->nullable()->unique();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['addon_id', 'archived_at']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX addon_prices_active_unique
            ON addon_prices (addon_id, billing_interval, currency)
            WHERE archived_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_prices');
    }
};
