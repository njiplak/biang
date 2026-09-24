<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('scheduled_plan_price_id')->nullable()->after('plan_price_id')
                ->constrained('plan_prices')->nullOnDelete();
            $table->timestamp('scheduled_change_at')->nullable()->after('scheduled_plan_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_plan_price_id');
            $table->dropColumn('scheduled_change_at');
        });
    }
};
