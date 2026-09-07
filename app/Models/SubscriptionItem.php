<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionItem extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriptionItemFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id', 'subscription_id', 'addon_id',
        'addon_price_id', 'quantity', 'dodo_item_id',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function addonPrice(): BelongsTo
    {
        return $this->belongsTo(AddonPrice::class);
    }
}
