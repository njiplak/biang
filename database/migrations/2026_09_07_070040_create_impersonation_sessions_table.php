<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 10: "Enter a customer's workspace as them, with an obvious
        // banner saying so and a permanent record that we did it."
        //
        // `reason` is NOT nullable. Impersonation is the single most invasive
        // thing staff can do, and an unexplained one is indistinguishable from
        // abuse after the fact.
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('admin_user_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();

            $table->text('reason');
            $table->string('ticket_reference')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['admin_user_id', 'started_at']);
            $table->index(['workspace_id', 'started_at']);
        });

        // A staff member can only be inside one customer account at a time -
        // otherwise the audit trail cannot say which session an action belongs to.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX impersonation_sessions_one_open_per_admin
            ON impersonation_sessions (admin_user_id)
            WHERE ended_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
