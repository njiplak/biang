<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Section 8: we keep a summary, the document itself stays with Dodo. Every
 * money figure here is copied from them and never computed by us.
 */
class InvoiceSummary extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceSummaryFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id', 'subscription_id', 'dodo_invoice_id', 'number', 'status',
        'currency', 'subtotal_minor', 'tax_minor', 'total_minor',
        'period_start', 'period_end', 'issued_at', 'paid_at', 'hosted_url',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
