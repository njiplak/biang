<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('ulid')->nullable()->after('id');

            // last workspace selected in the switcher
            $table->foreignId('current_workspace_id')->nullable()->after('email_verified_at')
                ->constrained('workspaces')->nullOnDelete();

            // Spec section 12: one trial per person, ever - not per workspace.
            // Consumed by the act of STARTING a trial, so a Path C invitee who
            // joins someone else's trialing workspace keeps their own.
            $table->timestamp('trial_consumed_at')->nullable();
            $table->foreignId('trial_consumed_workspace_id')->nullable()
                ->constrained('workspaces')->nullOnDelete();

            $table->timestamp('last_seen_at')->nullable();
        });

        DB::table('users')->whereNull('ulid')->orderBy('id')
            ->each(fn ($user) => DB::table('users')
                ->where('id', $user->id)
                ->update(['ulid' => (string) Str::ulid()]));

        Schema::table('users', function (Blueprint $table) {
            $table->unique('ulid');
            $table->index('trial_consumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['ulid']);
            $table->dropIndex(['trial_consumed_at']);
            $table->dropConstrainedForeignId('current_workspace_id');
            $table->dropConstrainedForeignId('trial_consumed_workspace_id');
            $table->dropColumn(['ulid', 'trial_consumed_at', 'last_seen_at']);
        });
    }
};
