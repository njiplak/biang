<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which add-ons are purchasable on which plan.
        //
        // Spec section 4 caps this at 10 paid add-ons per plan - a Dodo limit.
        // It is NOT enforced here: a row-count ceiling is not expressible as an
        // index, and a CHECK cannot see sibling rows. It belongs in the admin
        // plan-editor validation, where it can produce a usable error message.
        Schema::create('plan_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['plan_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_addons');
    }
};
