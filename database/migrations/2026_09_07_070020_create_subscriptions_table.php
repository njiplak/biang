<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OUR subscription state, reconciled from Dodo - not a mirror of theirs.
        // Spec section 8: our records are the source of truth for access, theirs
        // for money. Section 14 phase 3 requires this table to work with no
        // payment provider wired up at all.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            // the exact immutable price row this was sold on
            $table->foreignId('plan_price_id')->constrained()->restrictOnDelete();

            $table->string('status', 32);

            // dodo | manual. Without this, a null dodo_subscription_id cannot be
            // told apart from a broken sync. See App\Enums\BillingSource.
            $table->string('billing_source', 16)->default('dodo');
            $table->string('dodo_subscription_id')->nullable()->unique();

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // Provider event timestamp. Webhooks arrive out of order and more
            // than once; state only moves forward when the incoming event is
            // newer than this.
            $table->timestamp('provider_event_at')->nullable();

            // section 10: staff grant a plan by hand, and we keep who and why
            $table->foreignId('granted_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();
            $table->text('grant_reason')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('current_period_end');
            // section 4: drives the 3-day and 1-day trial warning emails, which
            // section 16 calls a launch blocker
            $table->index(['status', 'trial_ends_at']);

            // Lets child tables foreign-key on (workspace_id, id) so a row can
            // never reference another workspace's subscription.
            $table->unique(['workspace_id', 'id']);
        });

        // Section 12: one payment account per workspace, so ownership transfers
        // cleanly. At most one live subscription at a time.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_live_per_workspace
            ON subscriptions (workspace_id)
            WHERE status IN ('trialing', 'active', 'past_due')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
