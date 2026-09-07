<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Section 10: staff override a limit for one customer, with a reason on record. */
class WorkspaceEntitlementOverride extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceEntitlementOverrideFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id', 'feature_id', 'value', 'reason',
        'granted_by_admin_id', 'expires_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function grantedByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'granted_by_admin_id');
    }
}
