<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Membership defines tenancy, so this table is never auto-scoped by the
        // workspace global scope - the switcher has to read across workspaces.
        //
        // `role` is a plain column backed by a PHP enum rather than spatie teams:
        // the five roles in spec section 3 are fixed and defined by us, and this
        // row needs its own metadata (joined_at, invited_by) that model_has_roles
        // has nowhere to put.
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // owner | admin | billing_manager | member | viewer
            $table->string('role', 32);

            $table->foreignId('invited_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
            // section 3: "must always have at least one owner" checks this
            $table->index(['workspace_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
