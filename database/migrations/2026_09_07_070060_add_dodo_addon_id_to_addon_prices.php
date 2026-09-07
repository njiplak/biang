<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An add-on is its own resource at Dodo, not a product.
     *
     * Products are what a subscription is FOR; add-ons are what hangs off one,
     * and their API takes an addon id in a different field. `dodo_product_id`
     * on this table is therefore the wrong shape for what we actually need to
     * store, and reusing it would put an addon id in a column whose name says
     * product - the kind of lie that costs somebody a day.
     *
     * Dodo's add-on carries its own price and inherits the subscription's
     * interval, so one of their add-ons maps to one row here, not to the
     * `addons` row above it.
     */
    public function up(): void
    {
        Schema::table('addon_prices', function (Blueprint $table) {
            $table->string('dodo_addon_id')->nullable()->unique()->after('dodo_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('addon_prices', function (Blueprint $table) {
            $table->dropUnique(['dodo_addon_id']);
            $table->dropColumn('dodo_addon_id');
        });
    }
};
