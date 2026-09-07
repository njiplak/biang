<?php

use App\Contract\Workspace\MembershipContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\LastOwnerCannotLeave;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Features;

beforeEach(function () {
    $this->service = app(MembershipContract::class);
    Feature::factory()->create(['key' => Features::SEATS]);
    $plan = Plan::factory()->free()->create();
    $plan->features()->attach(Feature::where('key', Features::SEATS)->first(), ['value' => 10]);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    $this->ownerMembership = $this->workspace->owners()->first();
});

it('changes a role', function () {
    $member = WorkspaceMember::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Member]);

    $this->service->changeRole($member, WorkspaceRole::Admin);

    expect($member->fresh()->role)->toBe(WorkspaceRole::Admin);
});

// Section 3: a workspace must always have at least one owner.
it('refuses to demote the last owner', function () {
    expect(fn () => $this->service->changeRole($this->ownerMembership, WorkspaceRole::Admin))
        ->toThrow(LastOwnerCannotLeave::class);

    expect($this->ownerMembership->fresh()->role)->toBe(WorkspaceRole::Owner);
});

it('refuses to remove the last owner', function () {
    expect(fn () => $this->service->remove($this->ownerMembership))
        ->toThrow(LastOwnerCannotLeave::class);

    expect($this->workspace->fresh()->owners()->count())->toBe(1);
});

it('allows demoting an owner once another owner exists', function () {
    $second = WorkspaceMember::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Owner]);

    $this->service->changeRole($second, WorkspaceRole::Member);

    expect($second->fresh()->role)->toBe(WorkspaceRole::Member)
        ->and($this->workspace->fresh()->owners()->count())->toBe(1);
});

it('removes a member and frees their seat', function () {
    $member = WorkspaceMember::factory()->for($this->workspace)->create();
    expect($this->workspace->fresh()->seatsUsed())->toBe(2);

    $this->service->remove($member);

    expect($this->workspace->fresh()->seatsUsed())->toBe(1)
        ->and(app(App\Contract\Billing\UsageContract::class)->current($this->workspace, Features::SEATS))->toBe(1);
});

// Section 7: removing people is how a workspace gets back under its seat limit,
// so writing has to be restored the moment they do.
it('lifts the hard block when removing a member brings usage back under the limit', function () {
    $plan = Plan::where('is_free', true)->first();
    $plan->features()->updateExistingPivot(Feature::where('key', Features::SEATS)->first()->id, ['value' => 1]);

    $extra = WorkspaceMember::factory()->for($this->workspace)->create();
    app(App\Contract\Billing\EntitlementContract::class)->rebuild($this->workspace);
    $this->service->syncSeats($this->workspace);
    expect($this->workspace->fresh()->canWrite())->toBeFalse();

    $this->service->remove($extra);

    expect($this->workspace->fresh()->canWrite())->toBeTrue();
});
