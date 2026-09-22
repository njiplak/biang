<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Section 12 sells one trial per person, ever - but until now that was
         * only ENFORCED once Dodo's webhook landed and set trial_consumed_at.
         * Between opening the card form and that webhook there was a window,
         * seconds wide at best and unbounded when delivery fails, in which
         * hasConsumedTrial() still answered false. Two tabs on two workspaces
         * both passed the check and both got fourteen free days on one card.
         *
         * This is the claim staked when the checkout is CREATED, which closes
         * it. Deliberately separate columns from trial_consumed_*: a claim is
         * not a consumption. An abandoned checkout must expire quietly and
         * leave the person their trial, which is why this carries its own
         * timestamp to age out against rather than reusing the permanent one.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_checkout_at')->nullable();
            $table->foreignId('trial_checkout_workspace_id')->nullable()
                ->constrained('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_checkout_workspace_id');
            $table->dropColumn('trial_checkout_at');
        });
    }
};
