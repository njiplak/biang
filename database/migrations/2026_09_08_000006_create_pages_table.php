<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff-written content addressed by slug.
     *
     * The reason it exists now: spec section 11 owes a terms and a privacy
     * page, and section 4 takes a card up front - so signup has to link to
     * something. Holding that as a row rather than a blade file means legal
     * copy changes without a deploy, which is the same argument section 10
     * makes for plans and prices.
     *
     * Nothing renders these yet; where they are rendered - in this app, or
     * published to the marketing site the way `/api/pricing` already is - is
     * still open.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // The address. Unique because it IS the identifier a renderer
            // looks a page up by.
            $table->string('slug')->unique();
            $table->string('title');

            // Longer than `text` on purpose: a terms document is not a notice,
            // and truncating one silently is not a failure anybody would spot.
            $table->longText('body');

            /*
             * Draft by default. Legal copy is written over several sittings and
             * a half-finished terms page is worse than none, so publishing is
             * an explicit act rather than a side effect of saving.
             */
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();

            $table->timestamps();

            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
