<?php

use App\Enums\BillingSource;
use App\Enums\SubscriptionStatus;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

it('casts subscription enums', function () {
    $sub = Subscription::factory()->create();

    expect($sub->status)->toBeInstanceOf(SubscriptionStatus::class)
        ->and($sub->billing_source)->toBeInstanceOf(BillingSource::class);
});

// Section 12: one payment account per workspace, so ownership transfers cleanly.
it('permits only one live subscription per workspace', function () {
    $ws = Workspace::factory()->create();
    Subscription::factory()->for($ws)->create(['status' => SubscriptionStatus::Active]);

    expect(fn () => Subscription::factory()->for($ws)->create(['status' => SubscriptionStatus::Trialing]))
        ->toThrow(QueryException::class);
});

it('allows ended subscriptions to sit alongside a live one', function () {
    $ws = Workspace::factory()->create();
    Subscription::factory()->for($ws)->count(2)->create(['status' => SubscriptionStatus::Canceled]);
    Subscription::factory()->for($ws)->create(['status' => SubscriptionStatus::Active]);

    expect($ws->subscriptions()->count())->toBe(3)
        ->and($ws->subscription()->first()->status)->toBe(SubscriptionStatus::Active);
});

// The composite foreign key makes this impossible at the database level rather
// than relying on a global scope somebody might forget.
it('refuses to attach an item to another workspace subscription', function () {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();
    $sub = Subscription::factory()->for($theirs)->create();
    $addon = Addon::factory()->create();
    $price = AddonPrice::factory()->for($addon)->create();

    expect(fn () => SubscriptionItem::create([
        'workspace_id' => $mine->id,
        'subscription_id' => $sub->id,
        'addon_id' => $addon->id,
        'addon_price_id' => $price->id,
        'quantity' => 1,
    ]))->toThrow(QueryException::class);
});

// Section 8: a comped account granted by sales has no payment behind it. Without
// billing_source, a null provider id cannot be told apart from a broken sync.
it('distinguishes a comped subscription from a broken sync', function () {
    $comped = Subscription::factory()->manual()->create();
    $synced = Subscription::factory()->create(['dodo_subscription_id' => 'sub_live_123']);

    expect($comped->dodo_subscription_id)->toBeNull()
        ->and($comped->billing_source)->toBe(BillingSource::Manual)
        ->and($comped->isMissingProviderRecord())->toBeFalse()
        ->and($synced->isMissingProviderRecord())->toBeFalse();

    $broken = Subscription::factory()->create(['dodo_subscription_id' => null]);

    expect($broken->billing_source)->toBe(BillingSource::Dodo)
        ->and($broken->isMissingProviderRecord())->toBeTrue();
});

// Section 4: the trial auto-charges, and section 16 makes the warning emails a
// launch blocker - so finding trials about to end has to be reliable.
it('finds trials ending within a window', function () {
    Subscription::factory()->trialing()->create(['trial_ends_at' => now()->addDays(3)]);
    Subscription::factory()->trialing()->create(['trial_ends_at' => now()->addDays(9)]);
    Subscription::factory()->create(['status' => SubscriptionStatus::Active, 'trial_ends_at' => now()->addDays(3)]);

    expect(Subscription::trialsEndingBetween(now()->addDays(2), now()->addDays(4))->count())->toBe(1);
});

it('maps subscription status onto the workspace billing axis', function () {
    expect(SubscriptionStatus::Trialing->toBillingStatus()->value)->toBe('trialing')
        ->and(SubscriptionStatus::Expired->toBillingStatus()->value)->toBe('canceled');
});
