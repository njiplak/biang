<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\AccessStatus;
use App\Enums\BillingStatus;
use App\Enums\WorkspaceDisplayState;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->service = app(WorkspaceContract::class);
    $this->owner = User::factory()->create();
    $this->workspace = $this->service->create($this->owner, 'Acme Inc');
});

// Section 6: closing a workspace is recoverable for 30 days (configurable),
// then anonymised - so this is a soft delete with a purge date, never a wipe.
it('closes a workspace with a retention deadline rather than deleting it', function () {
    $this->service->closeWorkspace($this->workspace);

    $fresh = Workspace::withTrashed()->find($this->workspace->id);

    expect($fresh->access_status)->toBe(AccessStatus::Deleted)
        ->and($fresh->trashed())->toBeTrue()
        ->and($fresh->purge_after->isFuture())->toBeTrue()
        // Carbon 3 returns signed diffs, so compare the date itself
        ->and($fresh->purge_after->isSameDay(now()->addDays(config('workspace.retention_days'))))
        ->toBeTrue();
});

it('locks a closed workspace out entirely', function () {
    $this->service->closeWorkspace($this->workspace);

    $fresh = Workspace::withTrashed()->find($this->workspace->id);

    expect($fresh->displayState())->toBe(WorkspaceDisplayState::Deleted)
        ->and($fresh->canLogIn())->toBeFalse()
        ->and($fresh->canRead())->toBeFalse()
        ->and($fresh->canWrite())->toBeFalse();
});

// Section 6: a deleted workspace stops being billed.
it('cancels the subscription when the workspace closes', function () {
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))->first();
    app(SubscriptionContract::class)->grantPlan($this->workspace, $price, AdminUser::factory()->create(), 'seed');

    $this->service->closeWorkspace($this->workspace);

    $fresh = Workspace::withTrashed()->find($this->workspace->id);

    expect($fresh->billing_status)->toBe(BillingStatus::Free)
        ->and($fresh->subscriptions()->live()->count())->toBe(0);
});

// Nothing is destroyed, so the members and their data survive the retention
// window intact.
it('keeps members and data through the retention window', function () {
    $memberCount = $this->workspace->members()->count();

    $this->service->closeWorkspace($this->workspace);

    expect(Workspace::withTrashed()->find($this->workspace->id)->members()->count())
        ->toBe($memberCount);
});

it('restores a closed workspace to the free tier', function () {
    $this->service->closeWorkspace($this->workspace);

    $this->service->reopenWorkspace(Workspace::withTrashed()->find($this->workspace->id));

    $fresh = Workspace::find($this->workspace->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->access_status)->toBe(AccessStatus::Active)
        ->and($fresh->purge_after)->toBeNull()
        ->and($fresh->canWrite())->toBeTrue();
});

// A closed workspace must drop out of the switcher immediately.
it('disappears from the owner workspace list', function () {
    expect($this->owner->fresh()->workspaces()->count())->toBe(1);

    $this->service->closeWorkspace($this->workspace);

    expect($this->owner->fresh()->workspaces()->count())->toBe(0);
});

it('clears it as the current workspace for everyone in it', function () {
    expect($this->owner->fresh()->current_workspace_id)->toBe($this->workspace->id);

    $this->service->closeWorkspace($this->workspace);

    expect($this->owner->fresh()->current_workspace_id)->toBeNull();
});
