<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prices are immutable and versioned: an admin editing a price INSERTs a
        // new row and archives the old one. A subscription points at the exact
        // row it was sold on, so retiring a plan cannot reprice existing
        // customers (spec section 10).
        //
        // `billing_interval`, not `interval` - the latter is a Postgres type name
        // and needs quoting in every raw statement that touches it.
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_interval', 16); // month | year
            $table->char('currency', 3);

            // integer minor units. Never a float, and never our tax figure -
            // Dodo is merchant of record and owns tax (section 8).
            $table->bigInteger('amount_minor');

            $table->string('dodo_product_id')->nullable()->unique();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'archived_at']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX plan_prices_active_unique
            ON plan_prices (plan_id, billing_interval, currency)
            WHERE archived_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
