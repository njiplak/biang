<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger for METERED features only. Counters are aggregates;
        // this is the evidence behind them.
        //
        // Dodo is merchant of record and owns chargebacks (section 8). When a
        // customer disputes a metered bill we need the individual events - a
        // counter cannot be un-aggregated.
        //
        // No updated_at: a ledger row is never edited.
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key');
            $table->bigInteger('quantity');
            $table->timestamp('occurred_at');

            // the caller's key - makes double-reporting a no-op rather than
            // an overcharge
            $table->string('idempotency_key')->unique();

            $table->jsonb('metadata')->nullable();

            // set once pushed to Dodo for metered billing
            $table->timestamp('reported_at')->nullable();
            $table->string('dodo_event_id')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'feature_key', 'occurred_at']);
        });

        // The push queue: everything not yet reported to the provider.
        DB::statement(<<<'SQL'
            CREATE INDEX usage_records_unreported
            ON usage_records (occurred_at)
            WHERE reported_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
