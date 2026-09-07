<?php

namespace App\Models;

use App\Enums\EntitlementSource;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Materialised resolution of plan + add-ons + overrides. Section 7's hard block
 * runs on every write request, so this is one indexed lookup rather than four
 * joins.
 */
class WorkspaceEntitlement extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceEntitlementFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'feature_key', 'value', 'source', 'computed_at'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'source' => EntitlementSource::class,
            'computed_at' => 'datetime',
        ];
    }

    public function isUnlimited(): bool
    {
        return $this->value === null;
    }

    public function allows(int $usage): bool
    {
        return $this->isUnlimited() || $usage <= $this->value;
    }
}
