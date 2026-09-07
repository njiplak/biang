<?php

use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;

beforeEach(function () {
    $this->mine = Workspace::factory()->create();
    $this->theirs = Workspace::factory()->create();
});

it('returns everything when no workspace is current', function () {
    UsageCounter::factory()->for($this->mine)->create(['feature_key' => 'seats']);
    UsageCounter::factory()->for($this->theirs)->create(['feature_key' => 'seats']);

    expect(UsageCounter::count())->toBe(2);
});

it('hides other workspaces rows once a workspace is current', function () {
    UsageCounter::factory()->for($this->mine)->create(['feature_key' => 'seats']);
    UsageCounter::factory()->for($this->theirs)->create(['feature_key' => 'seats']);

    app(CurrentWorkspace::class)->set($this->mine);

    expect(UsageCounter::count())->toBe(1)
        ->and(UsageCounter::first()->workspace_id)->toBe($this->mine->id);
});

it('cannot reach another workspace row even by primary key', function () {
    $theirRow = UsageCounter::factory()->for($this->theirs)->create(['feature_key' => 'seats']);

    app(CurrentWorkspace::class)->set($this->mine);

    expect(UsageCounter::find($theirRow->id))->toBeNull();
});

it('stamps the current workspace onto new rows automatically', function () {
    app(CurrentWorkspace::class)->set($this->mine);

    $counter = UsageCounter::create(['feature_key' => 'projects', 'used' => 3]);

    expect($counter->workspace_id)->toBe($this->mine->id);
});

it('does not overwrite an explicitly given workspace id', function () {
    app(CurrentWorkspace::class)->set($this->mine);

    $counter = UsageCounter::withoutWorkspaceScope()
        ->create(['workspace_id' => $this->theirs->id, 'feature_key' => 'projects', 'used' => 1]);

    expect($counter->workspace_id)->toBe($this->theirs->id);
});

// The admin console, the workspace switcher and webhook processing all need to
// read across tenants. The escape hatch has to be explicit and obvious.
it('reads across workspaces when the scope is lifted', function () {
    Subscription::factory()->for($this->mine)->create();
    Subscription::factory()->for($this->theirs)->create();

    app(CurrentWorkspace::class)->set($this->mine);

    expect(Subscription::count())->toBe(1)
        ->and(Subscription::withoutWorkspaceScope()->count())->toBe(2);
});

it('restores the previous workspace after running unscoped', function () {
    app(CurrentWorkspace::class)->set($this->mine);

    $all = app(CurrentWorkspace::class)->runWithout(fn () => Subscription::count());

    expect($all)->toBe(0)
        ->and(app(CurrentWorkspace::class)->id())->toBe($this->mine->id);
});

it('forgets the current workspace on demand', function () {
    app(CurrentWorkspace::class)->set($this->mine);
    app(CurrentWorkspace::class)->forget();

    expect(app(CurrentWorkspace::class)->has())->toBeFalse();
});
