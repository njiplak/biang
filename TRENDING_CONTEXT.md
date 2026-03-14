# Trending Now Feature — Implementation Context

## Overview

A "Trending Now" page showing what's trending on TikTok and Instagram, using Apify scrapers to fetch data and displaying it in a card grid layout.

## Current Status

- **TikTok scraping**: Working with `codebyte/tiktok-trending-videos-insights` actor (US region, ZA not supported by this actor)
- **Instagram scraping**: Instagram hashtag scraper works (ingested 66 posts). Instagram location scraper (`scrapio/instagram-location-scraper`) returned 403 — may need a different actor.
- **Frontend**: Trending page with grid view, TrendCard component, platform/content filters, and manual "Scrape TikTok" button
- **CRUD pages**: Updated with new fields for trends and trend examples
- **Queue**: Jobs are queued — requires `php artisan queue:work` running, or set `QUEUE_CONNECTION=sync` in `.env`

## Environment Variables

```env
APIFY_API_TOKEN=<your_apify_token>
# Optional (defaults to https://api.apify.com/v2):
# APIFY_BASE_URL=https://api.apify.com/v2
```

## Key Commands

```bash
php artisan migrate                          # Run new migrations
php artisan db:seed --class=TrendTypeSeeder  # Seed new trend types (Location, Video, Creator)
php artisan trends:scrape tiktok             # Scrape TikTok trends
php artisan trends:scrape all                # Scrape all sources
php artisan queue:work                       # Process queued jobs
php artisan wayfinder:generate               # Regenerate frontend routes
bun run build                                # Build frontend (uses bun, NOT pnpm)
```

## Files Created (9)

| File | Purpose |
|------|---------|
| `database/migrations/2026_02_15_000001_add_trending_columns_to_trends_table.php` | Adds `region` (default 'ZA'), `rank` (nullable int), `source` (nullable string) to trends |
| `database/migrations/2026_02_15_000002_add_trending_columns_to_trend_examples_table.php` | Adds `title`, `caption`, `author_handle`, `author_avatar`, `content_type` (default 'video'), `media_url`, `platform_id` (FK) to trend_examples |
| `config/trending.php` | Hardcoded Apify actor IDs, Instagram hashtags & locations for SA |
| `app/Service/Integration/ApifyService.php` | HTTP client for Apify API sync endpoint. Methods: `runActorSync()`, `fetchTikTokTrends()`, `fetchInstagramHashtags()`, `fetchInstagramLocations()` |
| `app/Service/Integration/TrendIngestionService.php` | Maps raw Apify data → Trend + TrendExample records via `updateOrCreate`. Methods: `ingestTikTokTrends()`, `ingestInstagramHashtags()`, `ingestInstagramLocations()` |
| `app/Jobs/ScrapeTrendsJob.php` | Queued job, 10min timeout, accepts `$source` param (tiktok\|instagram_hashtag\|instagram_location\|all) |
| `app/Console/Commands/ScrapeTrends.php` | Artisan command `trends:scrape {source=all}` — dispatches ScrapeTrendsJob |
| `app/Http/Controllers/TrendingController.php` | `index()` renders Inertia page, `fetch()` returns paginated JSON (matching NextTable's `{items, current_page, total_page}` format), `scrape()` dispatches job via POST |
| `resources/js/pages/trending/index.tsx` | Grid page with TrendCard (thumbnail 9:16, badges, author, metrics, link-out), platform & content type filters, "Scrape TikTok" button with loading state |

## Files Modified (17)

| File | Changes |
|------|---------|
| `database/seeders/TrendTypeSeeder.php` | Added: Location, Video, Creator types |
| `app/Models/Trend.php` | Added `region`, `rank`, `source` to fillable + `rank` cast as integer |
| `app/Models/TrendExample.php` | Added `title`, `caption`, `author_handle`, `author_avatar`, `content_type`, `media_url`, `platform_id` to fillable + `platform()` BelongsTo |
| `app/Models/Platform.php` | Added `trendExamples()` HasMany |
| `config/services.php` | Added `apify.token` and `apify.base_url` |
| `bootstrap/app.php` | Added `->withSchedule()` — runs `trends:scrape all` twice daily at 6am/6pm |
| `routes/web/operational.php` | Added trending route group (index, fetch, scrape) |
| `app/Http/Requests/TrendRequest.php` | Added rules for `region`, `rank`, `source` |
| `app/Http/Requests/TrendExampleRequest.php` | Added rules for `title`, `caption`, `author_handle`, `author_avatar`, `content_type`, `media_url`, `platform_id` |
| `app/Service/Operational/TrendExampleService.php` | Added `'platform'` to `$relation` array |
| `resources/js/types/trend.ts` | Added `region`, `rank`, `source` |
| `resources/js/types/trend-example.ts` | Added all new fields + `platform?` relation |
| `resources/js/pages/operational/trend/form.tsx` | Added region, rank, source form fields |
| `resources/js/pages/operational/trend/index.tsx` | Added region, rank columns |
| `resources/js/pages/operational/trend-example/form.tsx` | Added title, caption, author_handle, author_avatar, content_type, media_url, platform_id fields |
| `resources/js/pages/operational/trend-example/index.tsx` | Added title, author_handle, content_type columns + post_url as clickable link |
| `resources/js/components/app-sidebar.tsx` | Added "Trending" nav item with TrendingUp icon → `/operational/trending` |

## Apify Actor Details

### TikTok: `codebyte/tiktok-trending-videos-insights`
- **Input**: `{ country: "US", period: "7", order_by: "vv", limit: 50 }`
- **Output fields**: `id`, `item_id`, `item_url`, `title`, `cover`, `duration`, `country_code`, `region`
- **Note**: ZA returned empty `[]` — actor may not support South Africa. US works fine.
- **Actors tried and rejected**:
  - `clockworks/tiktok-trends-scraper` — returns hashtag/topic trends, not videos
  - `lexis-solutions/tiktok-trending-videos-scraper` — paid ($39/month), free trial expired
  - `igview-owner/tiktok-data-scarper` — works, returns fields like `video_id`, `play_count`, `digg_count`, `author.unique_id`, `cover`, `play` (video URL)

### Instagram Hashtag: `apify/instagram-hashtag-scraper`
- **Input**: `{ hashtags: [...], resultsLimit: 10 }`
- **Output fields**: `id`, `shortCode`, `caption`, `ownerUsername`, `displayUrl`, `videoUrl`, `likesCount`, `commentsCount`, `videoViewCount`, `type`
- **Status**: Working — ingested 66 posts

### Instagram Location: `scrapio/instagram-location-scraper`
- **Input**: `{ locations: [...], resultsLimit: 10 }`
- **Status**: Returns 403 — may need different actor or auth

## TrendIngestionService Field Mapping (TikTok — codebyte actor)

```
item_id / id          → external_id
title                 → title, caption
item_url              → post_url
cover                 → thumbnail
country_code          → region
@author from item_url → author_handle
play (video URL)      → media_url
```

## TrendingController.fetch() Response Format

Must match NextTable's expected `Base<T[]>` format:
```json
{
  "items": [...],
  "prev_page": null,
  "current_page": 1,
  "next_page": 2,
  "total_page": 5,
  "per_page": 20
}
```
This is NOT the default Laravel `paginate()` format (which uses `data` key). The controller manually transforms it.

## Architecture Notes

- **Contract-Service pattern**: Interfaces → Services, bound in `ContractProvider`
- **BaseService.all()**: Returns `{items, current_page, total_page, ...}` format — standalone controllers (like TrendingController) must replicate this format manually
- **Wayfinder**: Auto-generates typed route files at `resources/js/routes/` from PHP routes. Run `php artisan wayfinder:generate` after adding/changing routes.
- **NextTable grid mode**: Uses `mode="grid"` with `gridRenderer` prop. Supports `params` prop for external state refresh via `_refresh` timestamp.
- **Queue**: Jobs dispatched via `ScrapeTrendsJob::dispatch($source)`. Need `php artisan queue:work` or `QUEUE_CONNECTION=sync`.

## Known Issues / TODOs

1. **ZA not supported** by `codebyte/tiktok-trending-videos-insights` — using US for demo
2. **Instagram location scraper** returns 403 — needs investigation or replacement
3. **Scrape button** waits 10 seconds then refreshes — no real-time job status tracking
4. **Intelephense warnings** on `ApifyService.php` (`failed()`, `status()`, `body()`, `json()`) are false positives — Laravel's Http facade works fine at runtime
5. **No settings controller** yet for managing hashtags/locations — currently hardcoded in `config/trending.php`
