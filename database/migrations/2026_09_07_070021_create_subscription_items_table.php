<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add-ons attached to a subscription.
        //
        // workspace_id is denormalised here on purpose. It is not just for
        // scoping: the composite foreign key below makes it structurally
        // impossible for an item to point at another workspace's subscription.
        // A global scope is policy; this is an invariant the database enforces.
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('subscription_id');
            $table->foreignId('addon_id')->constrained()->restrictOnDelete();
            $table->foreignId('addon_price_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('dodo_item_id')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'subscription_id'])
                ->references(['workspace_id', 'id'])
                ->on('subscriptions')
                ->cascadeOnDelete();

            $table->unique(['subscription_id', 'addon_id']);
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
