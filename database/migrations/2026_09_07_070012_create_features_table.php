<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This table is what unblocks spec section 13.1. The value metric is
        // still undecided, but whatever it turns out to be - seats, projects,
        // storage - it becomes a ROW here, not a column on `plans`. The limit
        // checker, the upgrade prompt and the admin plan editor never change.
        //
        // `key` is immutable once created: workspace_entitlements and
        // usage_counters denormalise it for the hot path.
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // limit | boolean | metered  (section 4's three add-on kinds map here)
            $table->string('type', 16);

            // gauge   = a current level, no period (seats, storage)
            // counter = accumulates over a period (api calls)
            $table->string('aggregation', 16);

            // none | billing_period | calendar_month
            // Free workspaces have no subscription, so they have no billing
            // period - metered features on free must use calendar_month.
            $table->string('reset_period', 24)->default('none');

            $table->string('unit', 32)->nullable();
            $table->boolean('is_enforced')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
