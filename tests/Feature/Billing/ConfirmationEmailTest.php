<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Notifications\Billing\AddonChangedNotification;
use App\Notifications\Billing\PlanChangedNotification;
use App\Support\Features;
use App\Support\SiteSettings;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Every change to what a customer pays leaves a written record in their inbox,
 * sent to the people who handle billing and nobody else.
 */

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->starter = Plan::firstWhere('code', 'starter');
    $this->starterPrice = PlanPrice::where('plan_id', $this->starter->id)->first();
    $this->proPrice = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))->first();

    subscriptions()->grantPlan($this->workspace, $this->starterPrice, AdminUser::factory()->create(), 'seed');

    $this->billing = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($this->billing)->billingManager()->create();

    $this->member = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($this->member)->create();
});

it('confirms a plan change to the people who handle billing', function () {
    Notification::fake();

    subscriptions()->changePlan($this->workspace->fresh(), $this->proPrice);

    Notification::assertSentTo([$this->owner, $this->billing], PlanChangedNotification::class);
    Notification::assertNotSentTo($this->member, PlanChangedNotification::class);
});

it('confirms buying and removing an add-on', function () {
    Notification::fake();

    $addon = Addon::factory()->quantity()
        ->for(Feature::firstWhere('key', Features::SEATS))
        ->create(['key' => 'extra-seat', 'grant_per_unit' => 1]);
    $price = AddonPrice::factory()->for($addon)->create(['amount_minor' => 900]);
    $this->starter->addons()->attach($addon);

    subscriptions()->purchaseAddon($this->workspace->fresh(), $price, 1);
    subscriptions()->changeAddonQuantity($this->workspace->fresh(), $addon, 0);

    Notification::assertSentToTimes($this->owner, AddonChangedNotification::class, 2);
});

it('ends billing emails with the support contact when one is set', function () {
    Setting::create(['key' => SiteSettings::SUPPORT_URL, 'value' => 'help@example.test']);

    $mail = PlanChangedNotification::for($this->workspace, 'Starter', $this->proPrice, false)
        ->toMail($this->owner);

    expect(implode("\n", $mail->outroLines))->toContain('help@example.test');
});

it('leaves the support line out until one is set', function () {
    $mail = PlanChangedNotification::for($this->workspace, 'Starter', $this->proPrice, false)
        ->toMail($this->owner);

    expect(implode("\n", array_merge($mail->introLines, $mail->outroLines)))->not->toContain('Contact us');
});
