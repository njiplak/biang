<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec section 10: staff create and edit plans without an engineer, and
        // retire a plan without breaking customers already on it. That is why a
        // plan is archived, never deleted, and why prices live in their own table.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_free')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['is_public', 'archived_at']);
        });

        // Exactly one free plan: cancelling drops a workspace onto it (section 6),
        // so an ambiguous "which free plan" would be an access bug.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX plans_single_free_plan
            ON plans (is_free)
            WHERE is_free = true
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
