<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec section 4's three kinds. An add-on grants a feature, exactly like
        // a plan does, so entitlement resolution has ONE code path rather than
        // one for "included in the plan" and another for "bought separately".
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // quantity | unlock | metered
            $table->string('kind', 16);

            $table->foreignId('feature_id')->nullable()
                ->constrained()->restrictOnDelete();

            // quantity kind: how much of the feature one unit grants (+1 seat)
            $table->bigInteger('grant_per_unit')->nullable();
            $table->unsignedInteger('max_quantity')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};
