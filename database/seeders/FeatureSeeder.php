<?php

namespace Database\Seeders;

use App\Enums\FeatureAggregation;
use App\Enums\FeatureType;
use App\Enums\ResetPeriod;
use App\Models\Feature;
use App\Support\Features;
use Illuminate\Database\Seeder;

/**
 * The feature catalogue.
 *
 * Spec section 13.1 leaves the headline value metric open, and this seeder does
 * not pre-empt it: when it is decided it becomes one more row here, with no
 * schema or code change. `seats` is seeded regardless because section 7's
 * invite flow has to count them whatever the pricing metric turns out to be.
 *
 * `key` is treated as immutable once created - workspace_entitlements and
 * usage_counters denormalise it - so this seeder matches on key and only ever
 * updates the descriptive columns.
 */
class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            [
                'key' => Features::SEATS,
                'name' => 'Seats',
                'description' => 'People with access to the workspace, including pending invitations.',
                'type' => FeatureType::Limit,
                'aggregation' => FeatureAggregation::Gauge,
                'reset_period' => ResetPeriod::None,
                'unit' => 'seat',
                'sort_order' => 10,
            ],
            [
                'key' => 'projects',
                'name' => 'Projects',
                'description' => 'Placeholder limit pending the value metric decision (section 13.1).',
                'type' => FeatureType::Limit,
                'aggregation' => FeatureAggregation::Gauge,
                'reset_period' => ResetPeriod::None,
                'unit' => 'project',
                'sort_order' => 20,
            ],
            [
                'key' => 'api_calls',
                'name' => 'API calls',
                'description' => 'Metered usage. Free workspaces have no billing period, so this resets on the calendar month.',
                'type' => FeatureType::Metered,
                'aggregation' => FeatureAggregation::Counter,
                'reset_period' => ResetPeriod::CalendarMonth,
                'unit' => 'call',
                'sort_order' => 30,
            ],
        ];

        foreach ($features as $feature) {
            Feature::updateOrCreate(['key' => $feature['key']], $feature);
        }
    }
}
