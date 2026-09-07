<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purpose-built rather than spatie/laravel-activitylog: the actor is
        // polymorphic across TWO guards (users and admin_users), workspace_id
        // has to be a first-class indexed column for section 10's customer
        // directory, and actions taken while impersonating must link back to
        // the session that authorised them.
        //
        // Append-only: no updated_at, and nothing in the app updates a row here.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // null for platform-level actions with no customer attached
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();

            // App\Models\User or App\Models\AdminUser; null for system actions
            $table->nullableMorphs('actor');

            // set when the action happened inside an impersonation session, so
            // "the customer did this" and "we did this as them" stay separable
            $table->foreignId('impersonation_session_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('action');
            $table->nullableMorphs('subject');

            $table->jsonb('changes')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
