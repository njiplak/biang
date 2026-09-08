<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a customer who has been impersonated delete their account.
     *
     * `user_id` was `restrictOnDelete`, and users are hard-deleted - so
     * AccountService::deleteAccount() hit a foreign key violation and
     * DELETE /settings/profile answered with a 500. It failed for exactly the
     * customers most likely to have been impersonated: the ones who contacted
     * support. routes/web/settings.php states the rule it broke - "Deleting is
     * the exit, and the exit is never blocked."
     *
     * Nulling rather than cascading, deliberately. Section 10 wants "a
     * permanent record that we did it", so the row has to survive: the reason,
     * the timestamps and which staff member it was all stay, and only the
     * pointer to the person goes. Cascading would let anyone erase the record
     * of their own impersonation by closing their account.
     *
     * `admin_user_id` is left restricted on purpose. Staff are soft-deleted
     * (see StaffService), so it never fires, and the staff member's identity is
     * the part of the record that matters most.
     */
    public function up(): void
    {
        /*
         * Dropped and rebuilt by hand around the column change.
         *
         * The original is a PARTIAL unique index - one open session per staff
         * member, `WHERE ended_at IS NULL` - created with raw SQL because no
         * schema builder expresses that. SQLite implements a column change by
         * copying the whole table, and the copy brought this index back
         * WITHOUT its condition, quietly turning "one open session per admin"
         * into "one session per admin, ever": the second time a staff member
         * impersonated anybody, they got a unique violation.
         *
         * Postgres alters in place and never noticed, which is exactly why
         * this is done explicitly rather than left to the rebuild.
         */
        $this->dropOneOpenPerAdminIndex();

        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        $this->createOneOpenPerAdminIndex();
    }

    public function down(): void
    {
        /*
         * Rows whose user has since been erased cannot point at anybody again,
         * and there is no honest value to invent for them. They are deleted so
         * the column can go back to being required - which is a real loss of
         * audit history, and the reason to think twice before rolling back.
         */
        DB::table('impersonation_sessions')->whereNull('user_id')->delete();

        $this->dropOneOpenPerAdminIndex();

        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        $this->createOneOpenPerAdminIndex();
    }

    private function dropOneOpenPerAdminIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS impersonation_sessions_one_open_per_admin');
    }

    /** Section 10: a staff member can only be inside one customer account at a time. */
    private function createOneOpenPerAdminIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX impersonation_sessions_one_open_per_admin
            ON impersonation_sessions (admin_user_id)
            WHERE ended_at IS NULL
        SQL);
    }
};
