<?php

use App\Contract\Billing\UsageContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\NoFreePlanConfigured;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceMember;
use App\Support\Features;

beforeEach(function () {
    $this->service = app(WorkspaceContract::class);
    $this->seats = Feature::factory()->create(['key' => Features::SEATS]);
});

function withFreePlan(int $seats = 3): Plan
{
    $plan = Plan::factory()->free()->create();
    $plan->features()->attach(Feature::where('key', Features::SEATS)->first(), ['value' => $seats]);

    return $plan;
}

// Spec section 5 Path A: sign up, name your workspace, you are in on the free
// tier. The owner membership and the entitlement snapshot are part of "created",
// not a follow-up step someone might forget.
it('creates a workspace with its owner, entitlements and seat count in one go', function () {
    withFreePlan();
    $user = User::factory()->create();

    $workspace = $this->service->create($user, 'Acme Inc');

    expect($workspace->name)->toBe('Acme Inc')
        ->and($workspace->billing_status)->toBe(BillingStatus::Free)
        ->and($workspace->owners()->count())->toBe(1)
        ->and($user->fresh()->roleIn($workspace))->toBe(WorkspaceRole::Owner)
        ->and(WorkspaceEntitlement::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(app(UsageContract::class)->current($workspace, Features::SEATS))->toBe(1);
});

it('gives every workspace a distinct slug', function () {
    withFreePlan();
    $user = User::factory()->create();

    $first = $this->service->create($user, 'Acme Inc');
    $second = $this->service->create(User::factory()->create(), 'Acme Inc');

    expect($first->slug)->not->toBe($second->slug);
});

it('points the creator at their new workspace', function () {
    withFreePlan();
    $user = User::factory()->create();

    $workspace = $this->service->create($user, 'Acme Inc');

    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);
});

// THE transaction test. The service layer owns the transaction, so a failure
// anywhere inside create() must leave nothing behind - no orphan workspace, no
// membership pointing at it, no half-built tenant.
it('commits nothing at all when entitlement resolution fails', function () {
    // deliberately no free plan configured
    $user = User::factory()->create();

    expect(fn () => $this->service->create($user, 'Acme Inc'))
        ->toThrow(NoFreePlanConfigured::class);

    expect(Workspace::count())->toBe(0)
        ->and(WorkspaceMember::count())->toBe(0)
        ->and(WorkspaceEntitlement::withoutWorkspaceScope()->count())->toBe(0)
        ->and($user->fresh()->current_workspace_id)->toBeNull();
});

// Section 3: the last owner has to hand ownership over before they can leave.
it('transfers ownership and demotes the previous owner to admin', function () {
    withFreePlan();
    $owner = User::factory()->create();
    $workspace = $this->service->create($owner, 'Acme Inc');
    $successor = WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceRole::Member]);

    $this->service->transferOwnership($workspace, $successor);

    expect($successor->fresh()->role)->toBe(WorkspaceRole::Owner)
        ->and($owner->fresh()->roleIn($workspace))->toBe(WorkspaceRole::Admin)
        ->and($workspace->owners()->count())->toBe(1);
});

it('suspends a workspace with the staff member and reason on record', function () {
    withFreePlan();
    $workspace = $this->service->create(User::factory()->create(), 'Acme Inc');
    $admin = AdminUser::factory()->create();

    $this->service->suspend($workspace, $admin, 'Payment fraud investigation');

    $fresh = $workspace->fresh();
    expect($fresh->canWrite())->toBeFalse()
        ->and($fresh->canRead())->toBeTrue()
        ->and($fresh->suspension_reason)->toBe('Payment fraud investigation')
        ->and($fresh->suspended_by_admin_id)->toBe($admin->id);
});

it('lifts a suspension', function () {
    withFreePlan();
    $workspace = $this->service->create(User::factory()->create(), 'Acme Inc');
    $this->service->suspend($workspace, AdminUser::factory()->create(), 'Investigation');

    $this->service->unsuspend($workspace);

    $fresh = $workspace->fresh();
    expect($fresh->canWrite())->toBeTrue()
        ->and($fresh->suspended_at)->toBeNull()
        ->and($fresh->suspension_reason)->toBeNull();
});
