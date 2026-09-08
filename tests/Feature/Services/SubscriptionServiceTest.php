<?php

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\DowngradeBlocked;
use App\Exceptions\Domain\TrialAlreadyConsumed;
use App\Exceptions\Domain\WorkspaceAlreadySubscribed;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Features;

beforeEach(function () {
    $this->service = app(SubscriptionContract::class);
    $this->entitlements = app(EntitlementContract::class);
    $this->seats = Feature::factory()->create(['key' => Features::SEATS]);

    /*
     * Unlimited, mirroring the seeded floor. An expired workspace cannot write
     * at all, so a ceiling here would enforce nothing and would only mislabel
     * why a cancelled workspace is blocked.
     */
    $this->floor = Plan::factory()->floor()->create();
    $this->floor->features()->attach($this->seats, ['value' => null]);

    // A small PAID plan. The floor is not sellable, so a test about moving
    // between plans has to start on one somebody could actually buy.
    $this->basic = Plan::factory()->create(['code' => 'basic']);
    $this->basic->features()->attach($this->seats, ['value' => 2]);
    $this->basicPrice = PlanPrice::factory()->for($this->basic)->create(['amount_minor' => 900]);

    $this->pro = Plan::factory()->create(['code' => 'pro']);
    $this->pro->features()->attach($this->seats, ['value' => 10]);
    $this->proPrice = PlanPrice::factory()->for($this->pro)->create(['amount_minor' => 2900]);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

// Section 5 Path B: 14 day trial of a chosen paid plan.
it('starts a trial and applies the paid plan entitlements immediately', function () {
    $subscription = $this->service->startTrial($this->workspace, $this->proPrice, $this->owner);

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Trialing)
        ->and($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(10);
});

// Section 12: one trial per person, EVER - not per workspace.
it('consumes the person trial eligibility, not the workspace', function () {
    $this->service->startTrial($this->workspace, $this->proPrice, $this->owner);

    expect($this->owner->fresh()->hasConsumedTrial())->toBeTrue()
        ->and($this->owner->fresh()->trial_consumed_workspace_id)->toBe($this->workspace->id);
});

it('refuses a second trial even in a brand new workspace', function () {
    $this->service->startTrial($this->workspace, $this->proPrice, $this->owner);
    $second = app(WorkspaceContract::class)->create($this->owner, 'Acme Two');

    expect(fn () => $this->service->startTrial($second, $this->proPrice, $this->owner))
        ->toThrow(TrialAlreadyConsumed::class);
});

it('commits nothing when the trial is refused', function () {
    $this->owner->update(['trial_consumed_at' => now()]);

    expect(fn () => $this->service->startTrial($this->workspace, $this->proPrice, $this->owner))
        ->toThrow(TrialAlreadyConsumed::class);

    expect(Subscription::withoutWorkspaceScope()->count())->toBe(0)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

// Section 12: one payment account per workspace.
it('refuses to start a trial when a live subscription already exists', function () {
    $this->service->startTrial($this->workspace, $this->proPrice, $this->owner);

    expect(fn () => $this->service->startTrial($this->workspace, $this->proPrice, User::factory()->create()))
        ->toThrow(WorkspaceAlreadySubscribed::class);
});

// Section 14 phase 3: the product must be sellable by hand, with no provider.
it('grants a plan by hand with the staff member and reason recorded', function () {
    $admin = AdminUser::factory()->create();

    $subscription = $this->service->grantPlan($this->workspace, $this->proPrice, $admin, 'Launch partner comp');

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_source)->toBe(BillingSource::Manual)
        ->and($subscription->dodo_subscription_id)->toBeNull()
        ->and($subscription->isMissingProviderRecord())->toBeFalse()
        ->and($subscription->granted_by_admin_id)->toBe($admin->id)
        ->and($subscription->grant_reason)->toBe('Launch partner comp')
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(10);
});

// Section 4: the trial auto-charges on day 15. That is the whole point of
// taking the card.
it('converts a trial into a paid subscription', function () {
    $subscription = $this->service->startTrial($this->workspace, $this->proPrice, $this->owner);

    $converted = $this->service->convertTrial($subscription);

    expect($converted->status)->toBe(SubscriptionStatus::Active)
        ->and($converted->trial_ends_at)->not->toBeNull()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});

it('upgrades a plan and re-resolves entitlements', function () {
    $this->service->grantPlan($this->workspace, $this->basicPrice, AdminUser::factory()->create(), 'seed');
    expect($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(2);

    $this->service->changePlan($this->workspace, $this->proPrice);

    expect($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(10)
        ->and($this->workspace->fresh()->subscription->plan_id)->toBe($this->pro->id);
});

// Section 7: "Workspace has 8 members and wants to move to a 5-seat plan. The
// downgrade is BLOCKED until they remove three people. We tell them exactly
// how many."
it('blocks a downgrade that would leave the workspace over its seat limit', function () {
    $this->service->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');
    WorkspaceMember::factory()->for($this->workspace)->count(7)->create();

    $small = Plan::factory()->create(['code' => 'starter']);
    $small->features()->attach($this->seats, ['value' => 5]);
    $smallPrice = PlanPrice::factory()->for($small)->create();

    expect(fn () => $this->service->changePlan($this->workspace, $smallPrice))
        ->toThrow(DowngradeBlocked::class);
});

it('names exactly how many people must be removed', function () {
    $this->service->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');
    WorkspaceMember::factory()->for($this->workspace)->count(7)->create();

    $small = Plan::factory()->create(['code' => 'starter']);
    $small->features()->attach($this->seats, ['value' => 5]);
    $smallPrice = PlanPrice::factory()->for($small)->create();

    try {
        $this->service->changePlan($this->workspace, $smallPrice);
        $this->fail('expected DowngradeBlocked');
    } catch (DowngradeBlocked $e) {
        expect($e->excess)->toBe(3)
            ->and($e->limit)->toBe(5)
            ->and($e->used)->toBe(8);
    }
});

it('leaves the old plan in place when a downgrade is blocked', function () {
    $this->service->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');
    WorkspaceMember::factory()->for($this->workspace)->count(7)->create();

    $small = Plan::factory()->create(['code' => 'starter']);
    $small->features()->attach($this->seats, ['value' => 5]);

    try {
        $this->service->changePlan($this->workspace, PlanPrice::factory()->for($small)->create());
    } catch (DowngradeBlocked) {
        // expected
    }

    expect($this->workspace->fresh()->subscription->plan_id)->toBe($this->pro->id)
        ->and($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(10);
});

it('allows a downgrade once enough people have been removed', function () {
    $this->service->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');
    WorkspaceMember::factory()->for($this->workspace)->count(3)->create();

    $small = Plan::factory()->create(['code' => 'starter']);
    $small->features()->attach($this->seats, ['value' => 5]);

    $this->service->changePlan($this->workspace, PlanPrice::factory()->for($small)->create());

    expect($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(5);
});

// Section 6: "Cancelling does not delete anything. The workspace drops to the
// free tier and the data stays."
/*
 * Section 6 promised cancelling "does not delete anything". It still does not -
 * what changed is where it lands. There is no free tier to keep working on, so
 * the workspace goes read-only and stays that way, indefinitely.
 */
it('cancels to read-only and keeps every bit of the data', function () {
    $this->service->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');

    $this->service->cancel($this->workspace);

    $fresh = $this->workspace->fresh();
    expect($fresh->billing_status)->toBe(BillingStatus::Unpaid)
        ->and($fresh->canRead())->toBeTrue()
        ->and($fresh->canExport())->toBeTrue()
        ->and($fresh->canWrite())->toBeFalse()
        ->and($fresh->subscription)->toBeNull()
        // Unlimited on the floor: the read-only block is what stops writing,
        // so a ceiling here would only mislabel why.
        ->and($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBeNull();
});

it('keeps the cancelled subscription as history', function () {
    $this->service->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');

    $this->service->cancel($this->workspace);

    expect($this->workspace->fresh()->subscriptions()->count())->toBe(1)
        ->and($this->workspace->fresh()->subscriptions()->first()->status)->toBe(SubscriptionStatus::Canceled);
});

// Cancelling from a bigger plan can leave the workspace over the free limit -
// section 7 then applies, rather than us deleting anyone's data to make it fit.
/*
 * Cancelling used to drop a workspace onto the free tier, where too many
 * members made it "over limit". There is no free tier now, so the block is not
 * about a limit at all - it is that nobody is paying. Everybody keeps their
 * seat and the whole workspace goes read-only.
 *
 * The distinction is the customer-facing one: "remove three people" is advice
 * they can act on and would not fix this, and "subscribe again" is the truth.
 */
it('blocks writing after a cancellation without blaming a limit', function () {
    $this->service->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');
    WorkspaceMember::factory()->for($this->workspace)->count(4)->create();

    $this->service->cancel($this->workspace);

    $fresh = $this->workspace->fresh();
    expect($fresh->displayState())->toBe(App\Enums\WorkspaceDisplayState::Expired)
        ->and($fresh->isOverLimit())->toBeFalse()
        ->and($fresh->canWrite())->toBeFalse()
        ->and($fresh->canRead())->toBeTrue()
        // Nobody was removed to make the data fit.
        ->and($fresh->seatsUsed())->toBe(5);
});
