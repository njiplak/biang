<?php

namespace App\Models;

use App\Enums\FeatureAggregation;
use App\Enums\FeatureType;
use App\Enums\ResetPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Section 13.1's value metric lands here as a ROW. Whatever it turns out to be,
 * the limit checker and the plan editor do not change.
 *
 * `key` is immutable once created: workspace_entitlements and usage_counters
 * denormalise it for the hot path.
 */
class Feature extends Model
{
    /** @use HasFactory<\Database\Factories\FeatureFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'description', 'type', 'aggregation',
        'reset_period', 'unit', 'is_enforced', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => FeatureType::class,
            'aggregation' => FeatureAggregation::class,
            'reset_period' => ResetPeriod::class,
            'is_enforced' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_features')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function addons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Addon::class);
    }
}
