<?php

namespace App\Support;

use App\Models\ProductEvent;
use App\Models\User;
use App\Models\Workspace;
use Throwable;

/**
 * Records the funnel steps section 15 asks us to measure.
 *
 * Analytics must never break the thing it measures: a failed insert is
 * reported to the log and the customer's request carries on.
 */
final class ProductEvents
{
    /** The steps, in funnel order. The admin dashboard counts them in this order. */
    public const FUNNEL = [
        'signed_up',
        'email_verified',
        'workspace_created',
        'checkout_started',
        'trial_started',
        'subscription_activated',
    ];

    /** @param  array<string, mixed>  $properties */
    public static function record(string $name, ?User $user = null, ?Workspace $workspace = null, array $properties = []): void
    {
        try {
            ProductEvent::create([
                'name' => $name,
                'user_id' => $user?->id,
                'workspace_id' => $workspace?->id,
                'properties' => $properties === [] ? null : $properties,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
