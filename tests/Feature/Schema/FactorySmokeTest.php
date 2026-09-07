<?php

// Every factory must produce a persistable row. These are the foundation of
// every future test, so a broken one should fail here and not in the middle of
// a billing test three weeks from now.
$models = [
    App\Models\AdminUser::class,
    App\Models\Workspace::class,
    App\Models\WorkspaceMember::class,
    App\Models\WorkspaceInvitation::class,
    App\Models\Plan::class,
    App\Models\PlanPrice::class,
    App\Models\Feature::class,
    App\Models\PlanFeature::class,
    App\Models\Addon::class,
    App\Models\AddonPrice::class,
    App\Models\PlanAddon::class,
    App\Models\Subscription::class,
    App\Models\SubscriptionItem::class,
    App\Models\DunningState::class,
    App\Models\WorkspaceEntitlement::class,
    App\Models\WorkspaceEntitlementOverride::class,
    App\Models\UsageCounter::class,
    App\Models\UsageRecord::class,
    App\Models\NotificationLog::class,
    App\Models\WebhookEvent::class,
    App\Models\InvoiceSummary::class,
    App\Models\ImpersonationSession::class,
    App\Models\AuditLog::class,
    App\Models\Announcement::class,
    App\Models\AnnouncementDismissal::class,
];

it('persists a row', function (string $model) {
    $row = $model::factory()->create();

    expect($row->exists)->toBeTrue()
        ->and($model::query()->whereKey($row->getKey())->exists())->toBeTrue();
})->with($models);
