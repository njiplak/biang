<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Send-idempotency ledger. NOT Laravel's `notifications` table, which is
        // an inbox for the database channel and is never written by MailChannel.
        //
        // Section 16 calls the trial-ending emails a launch blocker: because the
        // trial auto-charges, a missed or duplicated warning turns a conversion
        // into a chargeback. unique(dedupe_key) is what makes a re-run of the
        // scheduled command, or a queue retry, physically unable to send twice.
        //
        // The grain is the milestone, not the recipient: "trial_warn_3d:sub_123"
        // is recorded once even though section 9 mails the owner and every
        // billing manager.
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('dedupe_key')->unique();
            $table->string('channel', 16)->default('mail');
            $table->timestamp('sent_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
