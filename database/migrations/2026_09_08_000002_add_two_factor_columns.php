<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The deliberate build that 2026_09_07_070050 asked for.
     *
     * That migration dropped these same three columns because they arrived with
     * the starter kit, Fortify was never installed, and nothing ever read or
     * wrote them - "an unused column that looks like a security feature is worse
     * than no column". They come back now with pragmarx/google2fa behind them
     * and a challenge on both login paths.
     *
     * Both tables, because staff can reach every customer's data and impersonate
     * them; leaving the higher-privilege guard as the weaker one would be an odd
     * place to stop.
     */
    public function up(): void
    {
        foreach (['users', 'admin_users'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // text, not string: both are encrypted at rest by the model's
                // casts, and ciphertext is far longer than the plain value.
                $blueprint->text('two_factor_secret')->nullable()->after('password');
                $blueprint->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');

                // Set only once a code has actually been entered. A secret
                // without this is an enrolment somebody abandoned half way, and
                // must never gate their login.
                $blueprint->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'admin_users'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn([
                    'two_factor_secret',
                    'two_factor_recovery_codes',
                    'two_factor_confirmed_at',
                ]);
            });
        }
    }
};
