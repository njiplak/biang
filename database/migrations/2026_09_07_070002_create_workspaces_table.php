<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The tenant, and the thing we bill (spec section 2).
        //
        // State is deliberately TWO axes, not one column. Spec section 6 lists
        // "Over limit" with billing "Unchanged", which means access and billing
        // move independently and are written by different actors: Dodo webhooks
        // move billing_status, the usage checker and staff move access_status.
        // Collapsing them into one column lets those two writers race.
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('slug')->unique();
            $table->string('name');

            // free | trialing | active | past_due | canceled
            // 'free' is the only value with no matching subscriptions row.
            $table->string('billing_status', 32)->default('free');

            // active | read_only | suspended | deleted
            $table->string('access_status', 32)->default('active');

            $table->timestamp('over_limit_at')->nullable();
            // which limits are breached, so section 7 can name them exactly
            $table->jsonb('over_limit_features')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->foreignId('suspended_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();

            // section 9: dunning grace window; read-only begins when this passes
            $table->timestamp('grace_ends_at')->nullable();

            $table->string('dodo_customer_id')->nullable()->unique();

            $table->jsonb('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();
            // section 6: recoverable for 30 days, then anonymised (configurable)
            $table->timestamp('purge_after')->nullable();
            $table->timestamp('anonymized_at')->nullable();

            $table->index('billing_status');
            $table->index('access_status');
            $table->index('purge_after');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
