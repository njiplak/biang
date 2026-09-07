<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Section 8: "We keep a summary; the document itself stays with the
        // provider." This exists so support can answer a billing question
        // without opening Dodo (section 10), and so the revenue dashboard has
        // something local to aggregate.
        //
        // Every money figure here is COPIED from Dodo, never computed by us -
        // they are merchant of record and own tax worldwide.
        Schema::create('invoice_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->string('dodo_invoice_id')->unique();
            $table->string('number')->nullable();
            $table->string('status', 24); // paid|open|void|refunded|disputed

            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // link to the real document, which we never store
            $table->text('hosted_url')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'issued_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_summaries');
    }
};
