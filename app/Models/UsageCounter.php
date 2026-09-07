<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Two shapes in one table:
 *   gauge   -> period_start IS NULL, one row per feature (seats, storage)
 *   counter -> one row per feature per period (api calls)
 */
class UsageCounter extends Model
{
    /** @use HasFactory<\Database\Factories\UsageCounterFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'feature_key', 'period_start', 'period_end', 'used'];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'used' => 'integer',
        ];
    }

    public function scopeGauge(Builder $query): Builder
    {
        return $query->whereNull('period_start');
    }

    public function isGauge(): bool
    {
        return $this->period_start === null;
    }
}
