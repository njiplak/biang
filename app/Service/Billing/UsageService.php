<?php

namespace App\Service\Billing;

use App\Contract\Billing\UsageContract;
use App\Enums\FeatureAggregation;
use App\Jobs\ReportUsageToProvider;
use App\Models\Feature;
use App\Models\UsageCounter;
use App\Models\UsageRecord;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Illuminate\Support\Facades\DB;

/**
 * Counts what a workspace has used, and decides section 7's hard block.
 *
 * Counter shape follows the feature's own aggregation: a gauge (seats, storage)
 * is a single row with no period, a counter (api calls) is one row per period.
 * Callers do not have to know which - they pass a feature key.
 */
class UsageService implements UsageContract
{
    public function setGauge(Workspace $workspace, string $featureKey, int $value): void
    {
        DB::transaction(function () use ($workspace, $featureKey, $value) {
            $this->counterFor($workspace, $featureKey)->update(['used' => max(0, $value)]);
        });
    }

    public function increment(Workspace $workspace, string $featureKey, int $by = 1): void
    {
        DB::transaction(function () use ($workspace, $featureKey, $by) {
            $counter = $this->counterFor($workspace, $featureKey);
            $counter->update(['used' => max(0, $counter->used + $by)]);
        });
    }

    public function current(Workspace $workspace, string $featureKey): int
    {
        return (int) ($this->findCounter($workspace, $featureKey)?->used ?? 0);
    }

    public function record(Workspace $workspace, string $featureKey, int $quantity, string $idempotencyKey, array $metadata = []): void
    {
        DB::transaction(function () use ($workspace, $featureKey, $quantity, $idempotencyKey, $metadata) {
            $alreadySeen = UsageRecord::withoutWorkspaceScope()
                ->where('idempotency_key', $idempotencyKey)
                ->exists();

            // Metered usage is money. A retried report must not bill twice.
            if ($alreadySeen) {
                return;
            }

            $record = UsageRecord::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'feature_key' => $featureKey,
                'quantity' => $quantity,
                'occurred_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);

            $counter = $this->counterFor($workspace, $featureKey);
            $counter->update(['used' => max(0, $counter->used + $quantity)]);

            /*
             * Section 4: a metered add-on is "billed on actual consumption",
             * and this row is the only record that the consumption happened.
             *
             * afterCommit, so a rolled-back transaction cannot bill a customer
             * for usage we did not keep. Queued, so nothing a customer is
             * waiting on depends on Dodo being reachable - and section 7's hard
             * block runs off the counter above, which has already moved.
             */
            ReportUsageToProvider::dispatch($record->id)->afterCommit();
        });
    }

    public function evaluate(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            $breached = [];

            $entitlements = WorkspaceEntitlement::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->get();

            foreach ($entitlements as $entitlement) {
                // Unlimited can never be breached.
                if ($entitlement->value === null) {
                    continue;
                }

                if ($this->current($workspace, $entitlement->feature_key) > $entitlement->value) {
                    $breached[] = $entitlement->feature_key;
                }
            }

            // Section 7: naming the specific limits is what lets the product
            // offer the exact upgrade that removes the block, rather than a
            // generic "you are over quota".
            $workspace->forceFill([
                'over_limit_at' => $breached === [] ? null : now(),
                'over_limit_features' => $breached === [] ? null : $breached,
            ])->save();
        });
    }

    private function counterFor(Workspace $workspace, string $featureKey): UsageCounter
    {
        [$periodStart, $periodEnd] = $this->periodFor($featureKey);

        return UsageCounter::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'feature_key' => $featureKey,
                'period_start' => $periodStart,
            ],
            ['period_end' => $periodEnd, 'used' => 0]
        );
    }

    private function findCounter(Workspace $workspace, string $featureKey): ?UsageCounter
    {
        [$periodStart] = $this->periodFor($featureKey);

        return UsageCounter::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('feature_key', $featureKey)
            ->where(fn ($query) => $periodStart === null
                ? $query->whereNull('period_start')
                : $query->where('period_start', $periodStart))
            ->first();
    }

    /** @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon} */
    private function periodFor(string $featureKey): array
    {
        $feature = Feature::query()->where('key', $featureKey)->first();

        // An unknown key is treated as a gauge: it has no entitlement either,
        // so allows() will refuse it regardless.
        if ($feature?->aggregation !== FeatureAggregation::Counter) {
            return [null, null];
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
    }
}
