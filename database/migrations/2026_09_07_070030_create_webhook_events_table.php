<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central table: a webhook arrives with no tenant context at all, so
        // workspace_id is resolved after parsing and stays nullable.
        //
        // Section 16: "we treat their notifications as authoritative and
        // reconcile". That makes this the audit trail for every state change
        // we did not initiate ourselves.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('dodo');

            // the provider's own event id - the idempotency key. Webhooks are
            // delivered more than once by design.
            $table->string('event_id');
            $table->string('event_type');

            $table->jsonb('payload');

            // Standard Webhooks signature check (HMAC-SHA256, 5 minute
            // tolerance) recorded rather than assumed - an unverified event
            // must never be allowed to move billing state.
            $table->boolean('signature_verified')->default(false);

            // the provider's timestamp, not ours. Drives the monotonic guard on
            // subscriptions.provider_event_at, since events arrive out of order.
            $table->timestamp('occurred_at')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();

            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index('event_type');
            $table->index(['workspace_id', 'occurred_at']);
        });

        // The work queue: anything received but not yet processed.
        DB::statement(<<<'SQL'
            CREATE INDEX webhook_events_unprocessed
            ON webhook_events (received_at)
            WHERE processed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
