<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two-factor columns arrived with the Laravel starter kit, but
     * laravel/fortify was never installed - nothing has ever read or written
     * them, and no row holds a value. An unused column that looks like a
     * security feature is worse than no column, so they go.
     *
     * If staff or customer 2FA is wanted later it should be a deliberate build
     * with a package behind it, not a revived leftover.
     */
    public function up(): void
    {
        $this->dropFrom('users');
        $this->dropFrom('admin_users');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    private function dropFrom(string $table): void
    {
        $columns = array_values(array_filter(
            ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'],
            fn (string $column) => Schema::hasColumn($table, $column)
        ));

        if ($columns === []) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));
    }
};
