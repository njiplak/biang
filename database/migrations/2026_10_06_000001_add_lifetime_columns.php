<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lifetime plan is one payment at Dodo, never a subscription.
     *
     * `subscriptions.dodo_payment_id` is that payment: the provider record a
     * lifetime row has instead of a subscription id. Unique, so two deliveries
     * of the same purchase race into one row rather than two.
     *
     * `plan_prices.dodo_upgrade_product_id` is a second, pay-what-you-want
     * product per lifetime price. A lifetime customer moving up a tier pays the
     * difference through it - Dodo cannot prorate a payment that has no
     * subscription, so the amount is set by us at checkout.
     */
    public function up(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->string('dodo_upgrade_product_id')->nullable()->unique()->after('dodo_product_id');
        });

        // Rebuilt by hand around the change for the reason given in
        // add_scheduled_plan_change_to_subscriptions: an SQLite table copy
        // brings the partial index back without its condition.
        $this->dropOneLivePerWorkspaceIndex();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('dodo_payment_id')->nullable()->unique()->after('dodo_subscription_id');
        });

        $this->createOneLivePerWorkspaceIndex();
    }

    public function down(): void
    {
        $this->dropOneLivePerWorkspaceIndex();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['dodo_payment_id']);
            $table->dropColumn('dodo_payment_id');
        });

        $this->createOneLivePerWorkspaceIndex();

        Schema::table('plan_prices', function (Blueprint $table) {
            $table->dropUnique(['dodo_upgrade_product_id']);
            $table->dropColumn('dodo_upgrade_product_id');
        });
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
