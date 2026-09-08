<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * There is no free tier any more. Every plan carries a price and a card is
     * taken up front, so the state a workspace rests in when nobody is paying
     * is not "on the free plan" - it is unpaid, and it cannot write.
     *
     * The column is a plain string rather than a database enum, so this is a
     * value rename plus a new default. Nothing is lost: `free` and `unpaid`
     * describe the same set of workspaces, and the ones that were genuinely
     * using a free tier are exactly the ones who now have to choose a plan.
     */
    public function up(): void
    {
        DB::table('workspaces')->where('billing_status', 'free')->update(['billing_status' => 'unpaid']);

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('billing_status', 32)->default('unpaid')->change();
        });
    }

    public function down(): void
    {
        DB::table('workspaces')->where('billing_status', 'unpaid')->update(['billing_status' => 'free']);

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('billing_status', 32)->default('free')->change();
        });
    }
};
