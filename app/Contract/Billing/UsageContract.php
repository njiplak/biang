<?php

namespace App\Contract\Billing;

use App\Models\Workspace;

interface UsageContract
{
    /** Set a level outright (seats recounted, storage recalculated). */
    public function setGauge(Workspace $workspace, string $featureKey, int $value): void;

    /** Move a level by a delta. Never goes below zero. */
    public function increment(Workspace $workspace, string $featureKey, int $by = 1): void;

    public function current(Workspace $workspace, string $featureKey): int;

    /** Append a metered event. Replaying the same idempotency key is a no-op. */
    public function record(Workspace $workspace, string $featureKey, int $quantity, string $idempotencyKey, array $metadata = []): void;

    /** Compare usage to entitlements and move the workspace in or out of the hard block. */
    public function evaluate(Workspace $workspace): void;
}
