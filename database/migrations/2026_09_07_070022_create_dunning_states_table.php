<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per failed-payment episode (spec section 9). A separate table
        // rather than columns on subscriptions because section 15 measures
        // "failed-payment recovery rate", which needs the history of every
        // episode, not just the current one.
        Schema::create('dunning_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('subscription_id');

            $table->timestamp('started_at');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_failure_code')->nullable();
            $table->text('last_failure_message')->nullable();

            // when this passes, access drops to read-only (section 9 row 3)
            $table->timestamp('grace_ends_at');

            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 16)->nullable(); // recovered|canceled|suspended
            $table->timestamps();

            $table->foreign(['workspace_id', 'subscription_id'])
                ->references(['workspace_id', 'id'])
                ->on('subscriptions')
                ->cascadeOnDelete();

            $table->index('subscription_id');
            $table->index('grace_ends_at');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dunning_states_one_open_per_subscription
            ON dunning_states (subscription_id)
            WHERE resolved_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_states');
    }
};
