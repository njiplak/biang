<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 10: "Override a limit for one specific customer. Sales cannot
        // wait for a deploy." The reason is required and the admin is recorded,
        // because this is the mechanism by which a customer gets something they
        // did not pay for.
        Schema::create('workspace_entitlement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->restrictOnDelete();
            $table->bigInteger('value')->nullable(); // null = unlimited
            $table->text('reason');
            $table->foreignId('granted_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'feature_id']);
            $table->index('expires_at');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX entitlement_overrides_one_live_per_feature
            ON workspace_entitlement_overrides (workspace_id, feature_id)
            WHERE revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_entitlement_overrides');
    }
};
