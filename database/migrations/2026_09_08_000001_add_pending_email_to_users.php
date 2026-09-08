<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Changing an email address used to overwrite `email` and null out
     * `email_verified_at`, which left an established customer indistinguishable
     * from a signup who had never verified at all. That mattered the moment
     * verification became a gate rather than a notice: a typo in the new address
     * would have locked someone out of their own account, the exit included.
     *
     * The proof now stays with the address that still owns the account, and the
     * new one waits here until it is confirmed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');

            // Read by the profile form's uniqueness check on every save.
            // Deliberately NOT unique: two people may hold the same address
            // pending, and the first to confirm wins on the `email` unique
            // index. A unique index here would answer "is this address already
            // spoken for?" to anyone who asked.
            $table->index('pending_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['pending_email']);
            $table->dropColumn('pending_email');
        });
    }
};
