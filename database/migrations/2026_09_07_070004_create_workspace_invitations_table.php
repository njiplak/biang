<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A pending invitation reserves a seat. Without that, ten pending invites
        // all pass a 5-seat check and the workspace blows past its limit the
        // moment they accept (spec section 7).
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 32);

            // hash only - the plaintext token exists solely in the invite email
            $table->string('token_hash', 64)->unique();

            $table->foreignId('invited_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(1);
            $table->timestamps();

            $table->index(['workspace_id', 'accepted_at']);
            $table->index('email');
        });

        // One live invite per email per workspace. Case-insensitive, because
        // an invite to Bob@x.com and bob@x.com is the same person and would
        // otherwise reserve two seats.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX workspace_invitations_pending_unique
            ON workspace_invitations (workspace_id, lower(email))
            WHERE accepted_at IS NULL AND revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
