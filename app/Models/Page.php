<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Staff-written content addressed by slug - terms, privacy, anything else that
 * is copy rather than product.
 *
 * Central, not tenant-owned: a page belongs to the business, not to a
 * workspace, which is why it carries no workspace_id and is absent from the
 * tenancy scope list.
 */
class Page extends Model
{
    /** @use HasFactory<\Database\Factories\PageFactory> */
    use HasFactory;

    /*
     * The two documents section 11 owes. Named here because the CODE has to
     * find them - signup links to them, and PageSeeder creates them - and a
     * slug typed twice is a slug that eventually differs.
     */
    public const TERMS_SLUG = 'terms-of-service';

    public const PRIVACY_SLUG = 'privacy-policy';

    protected $fillable = [
        'ulid', 'slug', 'title', 'body', 'published_at', 'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $page) => $page->ulid ??= (string) Str::ulid());
    }

    /**
     * Published, and not at some future date.
     *
     * The date comparison is not decoration: it is what lets legal set a
     * change live at midnight rather than staying up to press a button.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lte(now());
    }

    /**
     * The body as HTML, safe to put on a page.
     *
     * Markdown rather than raw HTML is the whole reason this is safe to render
     * without a sanitiser afterwards, and both options below are load-bearing:
     *
     * - `html_input: strip` removes any HTML written into the markdown. Staff
     *   are trusted with the business, not with a `<script>` tag on a page
     *   every customer and every visitor loads; this also means a compromised
     *   or careless staff account cannot turn a terms page into an XSS.
     * - `allow_unsafe_links: false` drops `javascript:` and `data:` hrefs,
     *   which markdown link syntax would otherwise pass straight through.
     *
     * Change either one and the `dangerouslySetInnerHTML` on the public page
     * stops being defensible.
     */
    public function toHtml(): string
    {
        return Str::markdown($this->body ?? '', [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Where signup should point for terms and privacy, or null where the page
     * is not published.
     *
     * Null rather than a URL that 404s. The pages are seeded as drafts on
     * purpose, so until somebody writes the real copy the honest thing is to
     * show no link at all rather than one that leads nowhere.
     *
     * @return array{terms: string|null, privacy: string|null}
     */
    public static function legalLinks(): array
    {
        $live = static::query()->published()
            ->whereIn('slug', [self::TERMS_SLUG, self::PRIVACY_SLUG])
            ->pluck('slug')
            ->all();

        return [
            'terms' => in_array(self::TERMS_SLUG, $live, true)
                ? route('page.show', self::TERMS_SLUG)
                : null,
            'privacy' => in_array(self::PRIVACY_SLUG, $live, true)
                ? route('page.show', self::PRIVACY_SLUG)
                : null,
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }
}
