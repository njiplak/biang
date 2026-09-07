<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a workspace has actually used, checked against
        // workspace_entitlements to decide section 7's hard block.
        //
        // Two shapes in one table:
        //   gauge   (seats, storage)  -> period_start IS NULL, one row per feature
        //   counter (api calls)       -> one row per feature per period
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->bigInteger('used')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'feature_key']);
        });

        // Two partial indexes rather than one UNIQUE over a nullable column.
        // Postgres treats NULLs as distinct by default, so a plain unique on
        // (workspace_id, feature_key, period_start) would happily allow two
        // gauge rows for the same feature. PG15+ has NULLS NOT DISTINCT, but
        // SQLite (the test connection) does not, so this shape works on both.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX usage_counters_gauge_unique
            ON usage_counters (workspace_id, feature_key)
            WHERE period_start IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX usage_counters_period_unique
            ON usage_counters (workspace_id, feature_key, period_start)
            WHERE period_start IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
