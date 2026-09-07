<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    WorkspaceMember::factory()->for($this->workspace)->count(2)->create();
});

it('downloads the member list as a spreadsheet', function () {
    $this->actingAs($this->owner)
        ->get(route('workspace.member.export', $this->workspace))
        ->assertOk()
        ->assertDownload('acme-inc-members.xlsx');
});

// Section 6: "Suspended | Yes | Yes, export only | No" - getting your data out
// must outlive the states that stop you writing.
it('still exports while the workspace is suspended', function () {
    app(WorkspaceContract::class)->suspend($this->workspace, AdminUser::factory()->create(), 'Investigation');

    $this->actingAs($this->owner)
        ->get(route('workspace.member.export', $this->workspace->fresh()))
        ->assertOk();
});

it('still exports while the workspace is over its limit', function () {
    WorkspaceMember::factory()->for($this->workspace)->count(3)->create();
    app(App\Contract\Workspace\MembershipContract::class)->syncSeats($this->workspace);
    expect($this->workspace->fresh()->canWrite())->toBeFalse();

    $this->actingAs($this->owner)
        ->get(route('workspace.member.export', $this->workspace))
        ->assertOk();
});

it('refuses the export to a stranger', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('workspace.member.export', $this->workspace))
        ->assertForbidden();
});
