<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A downgrade takes effect at the next renewal instead of immediately, so
     * the customer keeps what they already paid for. Dodo holds the schedule;
     * these mirror it so the app can show it and apply it when it lands.
     */
    public function up(): void
    {
        /*
         * SQLite adds a foreign key by copying the whole table, and the copy
         * brings the partial one-live-subscription index back WITHOUT its
         * condition - "one live subscription per workspace" became "one
         * subscription per workspace, ever", so nobody could resubscribe after
         * cancelling. Rebuilt by hand, as in allow_erasing_an_impersonated_user.
         */
        $this->dropOneLivePerWorkspaceIndex();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('scheduled_plan_price_id')->nullable()->after('plan_price_id')
                ->constrained('plan_prices')->nullOnDelete();
            $table->timestamp('scheduled_change_at')->nullable()->after('scheduled_plan_price_id');
        });

        $this->createOneLivePerWorkspaceIndex();
    }

    public function down(): void
    {
        $this->dropOneLivePerWorkspaceIndex();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_plan_price_id');
            $table->dropColumn('scheduled_change_at');
        });

        $this->createOneLivePerWorkspaceIndex();
    }

    private function dropOneLivePerWorkspaceIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS subscriptions_one_live_per_workspace');
    }

    /** Section 12: at most one live subscription per workspace. */
    private function createOneLivePerWorkspaceIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_live_per_workspace
            ON subscriptions (workspace_id)
            WHERE status IN ('trialing', 'active', 'past_due')
        SQL);
    }
};
