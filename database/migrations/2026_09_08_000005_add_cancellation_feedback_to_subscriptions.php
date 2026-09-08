<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section 15 asks for "monthly churn, and how much of it is voluntary
     * versus failed payments". The split needs a reason attached to the
     * cancellation itself, and the answer has to live here rather than only at
     * Dodo: it is our metric, and asking them for it turns a dashboard query
     * into a rate-limited API call.
     *
     * Both nullable, always. The exit is never blocked, and that includes not
     * making somebody answer a question in order to leave.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('cancellation_feedback', 32)->nullable()->after('canceled_at');
            $table->text('cancellation_comment')->nullable()->after('cancellation_feedback');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cancellation_feedback', 'cancellation_comment']);
        });
    }
};
