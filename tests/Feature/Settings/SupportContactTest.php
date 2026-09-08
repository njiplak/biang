<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * Spec section 6 tells a suspended customer to "contact support", and until now
 * the banner said exactly that with nothing to click - a dead end in the one
 * state where the customer most needs a human.
 *
 * The destination is a setting rather than config so support can be changed
 * without a deploy, which is the same reason section 10 puts plans and prices
 * in the console.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->user = User::factory()->create();
    app(WorkspaceContract::class)->create($this->user, 'Acme Inc');
});

it('has no destination until somebody sets one', function () {
    expect(SiteSettings::supportUrl())->toBeNull();
});

/*
 * A placeholder rather than an empty row, so the console has something to show
 * and the flow is testable end to end. RFC 2606 reserves example.com, so if it
 * is ever forgotten the mail bounces instead of vanishing.
 */
it('seeds a placeholder address that is safe to forget', function () {
    $this->seed(SettingSeeder::class);

    expect(SiteSettings::supportUrl())->toBe('mailto:support@example.com');
});

// Re-seeding must never replace an address somebody set in the console.
it('does not overwrite a configured address when seeded again', function () {
    $this->seed(SettingSeeder::class);

    Setting::where('key', SiteSettings::SUPPORT_URL)
        ->update(['value' => 'https://acme.test/help']);

    $this->seed(SettingSeeder::class);

    expect(SiteSettings::supportUrl())->toBe('https://acme.test/help');
});

/*
 * Staff are asked for a "support destination" and most will type an address.
 * Handing that to href unchanged makes a relative link to a page that does not
 * exist, so a bare email becomes a mailto rather than a broken link.
 */
it('turns a bare email address into a mailto link', function () {
    Setting::create(['key' => SiteSettings::SUPPORT_URL, 'value' => 'help@acme.test']);

    expect(SiteSettings::supportUrl())->toBe('mailto:help@acme.test');
});

it('leaves a real url alone', function (string $value) {
    Setting::create(['key' => SiteSettings::SUPPORT_URL, 'value' => $value]);

    expect(SiteSettings::supportUrl())->toBe($value);
})->with([
    'https://acme.test/support',
    'mailto:help@acme.test',
    'https://acme.test/support?from=app',
]);

it('treats whitespace as unset rather than linking to nowhere', function () {
    Setting::create(['key' => SiteSettings::SUPPORT_URL, 'value' => '   ']);

    expect(SiteSettings::supportUrl())->toBeNull();
});

// ------------------------------------------------------------- the page

it('shares the destination with a signed-in customer', function () {
    Setting::create(['key' => SiteSettings::SUPPORT_URL, 'value' => 'help@acme.test']);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('support.url', 'mailto:help@acme.test'));
});

/*
 * Null, not absent: the banner has a plain-text fallback and needs to be able
 * to tell "nobody configured this" from "the prop never arrived".
 */
it('shares null when nothing is configured', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('support.url', null));
});

it('shares nothing at all with a guest', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('support', null));
});
