<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 10: "Announce maintenance or a new feature to all customers."
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('title');
            $table->text('body');

            // all | plan | state - with the specifics in audience_filter, so a
            // new targeting rule is a config change rather than a migration
            $table->string('audience', 24)->default('all');
            $table->jsonb('audience_filter')->nullable();

            $table->string('severity', 16)->default('info'); // info|warning|critical
            $table->boolean('is_dismissible')->default(true);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['published_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
