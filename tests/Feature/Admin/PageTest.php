<?php

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;

/*
 * Staff-written content addressed by slug.
 *
 * Section 11 owes a terms and a privacy page and section 4 takes a card up
 * front, so signup has to link to something. Holding that as rows means legal
 * copy changes without a deploy.
 *
 * Nothing renders these to the public yet - that decision is still open - so
 * everything here is about the staff side and the publish rules.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);

    $this->staff = AdminUser::factory()->create();
    $this->staff->assignRole('super-admin');
});

// ------------------------------------------------------------- separation

/*
 * Section 3: "A customer account can never reach admin functions." Pages are
 * no different for being content rather than money.
 */
it('keeps customers out entirely', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.page.index'))
        ->assertRedirect(route('admin.login'));
});

it('keeps out staff without the permission', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->get(route('admin.page.index'))
        ->assertForbidden();
});

// ------------------------------------------------------------- the CRUD

it('shows the list to staff who may see it', function () {
    Page::factory()->count(2)->create();

    $this->actingAs($this->staff, 'admin')
        ->get(route('admin.page.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/page/index'));
});

it('serves the list in the table envelope', function () {
    Page::factory()->count(3)->create();

    $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.page.fetch'))
        ->assertOk()
        ->assertJsonStructure(['items', 'current_page', 'next_page', 'prev_page', 'total_page'])
        ->assertJsonCount(3, 'items');
});

it('creates a draft by default', function () {
    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.page.store'), [
            'slug' => 'privacy-policy',
            'title' => 'Privacy Policy',
            'body' => 'What we collect and why.',
        ]);

    $page = Page::firstWhere('slug', 'privacy-policy');

    expect($page)->not->toBeNull()
        ->and($page->published_at)->toBeNull()
        ->and($page->isPublished())->toBeFalse()
        ->and($page->ulid)->not->toBeEmpty()
        ->and($page->created_by_admin_id)->toBe($this->staff->id);
});

it('publishes when asked to', function () {
    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.page.store'), [
            'slug' => 'terms',
            'title' => 'Terms of Service',
            'body' => 'The agreement.',
            'published' => true,
        ]);

    expect(Page::firstWhere('slug', 'terms')->isPublished())->toBeTrue();
});

/*
 * The date a document took effect is part of the document. Stamping now() on
 * every save would move it each time a typo was fixed.
 */
it('keeps the original publish date when an already published page is edited', function () {
    $page = Page::factory()->create(['published_at' => now()->subMonth()]);
    $originalDate = $page->published_at;

    $this->actingAs($this->staff, 'admin')
        ->put(route('admin.page.update', $page->id), [
            'slug' => $page->slug,
            'title' => 'Corrected title',
            'body' => $page->body,
            'published' => true,
        ]);

    $page->refresh();

    expect($page->title)->toBe('Corrected title')
        ->and($page->published_at->timestamp)->toBe($originalDate->timestamp);
});

it('stamps a fresh date when a draft is published for the first time', function () {
    $page = Page::factory()->create(['published_at' => null]);

    $this->actingAs($this->staff, 'admin')
        ->put(route('admin.page.update', $page->id), [
            'slug' => $page->slug,
            'title' => $page->title,
            'body' => $page->body,
            'published' => true,
        ]);

    expect($page->refresh()->isPublished())->toBeTrue();
});

it('unpublishes back to a draft', function () {
    $page = Page::factory()->published()->create();

    $this->actingAs($this->staff, 'admin')
        ->put(route('admin.page.update', $page->id), [
            'slug' => $page->slug,
            'title' => $page->title,
            'body' => $page->body,
            'published' => false,
        ]);

    expect($page->refresh()->published_at)->toBeNull();
});

it('deletes a page', function () {
    $page = Page::factory()->create();

    $this->actingAs($this->staff, 'admin')
        ->delete(route('admin.page.destroy', $page->id));

    expect(Page::find($page->id))->toBeNull();
});

// ------------------------------------------------------------- the slug

/*
 * The slug IS the address a renderer looks a page up by. Two rows answering to
 * one address is a coin toss over which document a customer is shown.
 */
it('refuses a slug that is already taken', function () {
    Page::factory()->create(['slug' => 'terms']);

    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.page.store'), [
            'slug' => 'terms',
            'title' => 'Another terms',
            'body' => 'Body.',
        ])
        ->assertSessionHasErrors('slug');

    expect(Page::where('slug', 'terms')->count())->toBe(1);
});

it('lets a page keep its own slug while being edited', function () {
    $page = Page::factory()->create(['slug' => 'terms']);

    $this->actingAs($this->staff, 'admin')
        ->put(route('admin.page.update', $page->id), [
            'slug' => 'terms',
            'title' => 'Edited',
            'body' => 'Body.',
        ])
        ->assertSessionHasNoErrors();

    expect($page->refresh()->title)->toBe('Edited');
});

it('refuses a slug that could not survive a url', function (string $slug) {
    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.page.store'), [
            'slug' => $slug,
            'title' => 'Title',
            'body' => 'Body.',
        ])
        ->assertSessionHasErrors('slug');
})->with(['Terms Of Service', 'Terms', 'terms/service', 'terms--service', '-terms', 'terms-']);

// ------------------------------------------------------------- publishing

/*
 * Three states, not two: a future date is published but not yet live, so legal
 * can queue a change without staying up to press a button.
 */
it('treats a future publish date as not yet live', function () {
    $scheduled = Page::factory()->scheduled()->create();

    expect($scheduled->isPublished())->toBeFalse()
        ->and(Page::published()->whereKey($scheduled->id)->exists())->toBeFalse();
});

it('lists only pages that are actually live', function () {
    $live = Page::factory()->published()->create();
    Page::factory()->create();
    Page::factory()->scheduled()->create();

    expect(Page::published()->pluck('id')->all())->toBe([$live->id]);
});

// ------------------------------------------------------------- audit

// Section 10 keeps a permanent record of what staff did; content is no different.
it('records who changed the copy', function () {
    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.page.store'), [
            'slug' => 'terms',
            'title' => 'Terms',
            'body' => 'Body.',
        ]);

    expect(AuditLog::where('action', 'page.created')->exists())->toBeTrue();
});

// ------------------------------------------------------------- seeding

/*
 * Section 11 owes a terms and a privacy page. They are seeded as DRAFTS: a
 * placeholder standing in for legal copy is exactly the half-finished page the
 * publish flag exists to keep off the internet.
 */
it('seeds the two pages section 11 owes, unpublished', function () {
    $this->seed(Database\Seeders\PageSeeder::class);

    $terms = Page::firstWhere('slug', 'terms-of-service');
    $privacy = Page::firstWhere('slug', 'privacy-policy');

    expect($terms)->not->toBeNull()
        ->and($privacy)->not->toBeNull()
        ->and($terms->isPublished())->toBeFalse()
        ->and($privacy->isPublished())->toBeFalse()
        ->and($terms->body)->toContain('PLACEHOLDER');
});

/*
 * The one that would actually hurt: a deploy re-running the seeders must not
 * replace legal copy somebody wrote with the placeholder it replaced.
 */
it('never overwrites real copy when seeded again', function () {
    $this->seed(Database\Seeders\PageSeeder::class);

    Page::where('slug', 'terms-of-service')->update([
        'body' => 'The real agreement.',
        'published_at' => now(),
    ]);

    $this->seed(Database\Seeders\PageSeeder::class);

    $terms = Page::firstWhere('slug', 'terms-of-service');

    expect($terms->body)->toBe('The real agreement.')
        ->and($terms->isPublished())->toBeTrue()
        ->and(Page::where('slug', 'terms-of-service')->count())->toBe(1);
});
