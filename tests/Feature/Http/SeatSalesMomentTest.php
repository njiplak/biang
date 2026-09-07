<?php

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\Features;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $starter = Plan::firstWhere('code', 'starter');
    $this->seatAddon = Addon::factory()->quantity()
        ->for(Feature::firstWhere('key', Features::SEATS))
        ->create(['key' => 'extra-seat', 'name' => 'Extra seat', 'grant_per_unit' => 1]);
    AddonPrice::factory()->for($this->seatAddon)->create(['amount_minor' => 900, 'currency' => 'USD']);
    $starter->addons()->attach($this->seatAddon);

    app(SubscriptionContract::class)->grantPlan(
        $this->workspace,
        PlanPrice::where('plan_id', $starter->id)->first(),
        AdminUser::factory()->create(),
        'seed',
    );

    // fill the 5 starter seats: owner + 4
    App\Models\WorkspaceMember::factory()->for($this->workspace)->count(4)->create();
    app(App\Contract\Workspace\MembershipContract::class)->syncSeats($this->workspace);
});

// Section 7: "A seat limit should be a sales moment, not a wall." The page has
// to carry the priced offer, or the UI has nothing to offer.
it('shows the priced seat add-on when the workspace is at its limit', function () {
    $this->actingAs($this->owner)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('seats.used', 5)
            ->where('seats.limit', 5)
            ->where('seat_offer.name', 'Extra seat')
            ->where('seat_offer.amount_minor', 900)
            ->where('seat_offer.currency', 'USD'));
});

// "If they take it, the invite goes out."
it('buys the seat and sends the invitation in one action', function () {
    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'sixth@example.com',
            'role' => 'member',
            'add_seat' => true,
        ])->assertRedirect();

    expect(WorkspaceInvitation::where('email', 'sixth@example.com')->exists())->toBeTrue()
        ->and(SubscriptionItem::withoutWorkspaceScope()->first()->quantity)->toBe(1)
        ->and(app(EntitlementContract::class)->limitFor($this->workspace, Features::SEATS))->toBe(6);
});

// "If they decline, the invite is blocked."
it('blocks the invitation when the seat is declined', function () {
    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'sixth@example.com',
            'role' => 'member',
        ])->assertSessionHasErrors('errors');

    expect(WorkspaceInvitation::where('email', 'sixth@example.com')->exists())->toBeFalse()
        ->and(SubscriptionItem::withoutWorkspaceScope()->count())->toBe(0);
});

// The seat must not be bought if the invite then fails - one unit of work.
it('buys no seat when the invitation itself is rejected', function () {
    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'not-an-email',
            'role' => 'member',
            'add_seat' => true,
        ])->assertSessionHasErrors('email');

    expect(SubscriptionItem::withoutWorkspaceScope()->count())->toBe(0);
});

// Section 12: a free workspace has no payment account, so the offer is an
// upgrade rather than a seat.
it('offers no seat add-on on the free tier', function () {
    $free = app(WorkspaceContract::class)->create(User::factory()->create(), 'Free Co');
    $freeOwner = $free->owners()->first()->user;

    $this->actingAs($freeOwner)
        ->get(route('workspace.member.index', $free))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('seat_offer', null));
});
