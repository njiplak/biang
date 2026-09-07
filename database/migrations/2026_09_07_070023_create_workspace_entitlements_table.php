<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A materialised, flattened resolution of plan features + purchased
        // add-ons + staff overrides. Rebuilt whenever any of those change.
        //
        // Why materialise: section 7's hard block runs on EVERY write request.
        // Resolving it live is four joins per request. This makes the check one
        // indexed lookup.
        //
        // feature_key is denormalised rather than a foreign key, deliberately -
        // this is a read projection on the hot path. The trade is that
        // features.key must be treated as immutable once created.
        Schema::create('workspace_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key');
            $table->bigInteger('value')->nullable(); // null = unlimited
            $table->string('source', 16);            // plan | addon | override
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_entitlements');
    }
};
