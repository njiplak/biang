<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section 8: their records are authoritative for money. A webhook is only
     * one way of hearing them - the other is asking directly, which is what the
     * checkout return and the drift check do.
     *
     * Both land in this table so there is ONE audit trail and one reconciler.
     * The column is what keeps them tellable apart: a pulled event has no
     * signature to verify, and `signature_verified` must keep meaning what it
     * says or it is worthless as evidence.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            // Defaulted, so every row already in the table is what it was: an
            // event Dodo delivered and we verified.
            $table->string('source', 16)->default('webhook')->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
