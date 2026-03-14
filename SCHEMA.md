# Kawakib - Basis - Models & Migrations Instructions

## Overview

Create Laravel models with migrations, seeders, and factories in the following order. Run each command sequentially to avoid foreign key dependency issues.

---

## 1. Platform

**Description:**  
Lookup table for social media platforms. This is the foundation for tracking where content comes from. Currently supports TikTok and Instagram, but designed to be extensible.

**Correlations:**  
- Used by: Creators, Trends, Assets, BrandRecognitionHits

**Main Function:**  
- Define available social media platforms
- Filter data by platform across the system

```bash
php artisan make:model Platform -msf
```

**Migration fields:**

```php
Schema::create('platforms', function (Blueprint $table) {
    $table->id();
    $table->string('name');              // TikTok, Instagram
    $table->string('slug')->unique();
    $table->string('icon')->nullable();
    $table->string('base_url')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 2. TrendCategory

**Description:**  
Lookup table for categorizing trends. Helps marketers filter and browse trends by topic area.

**Correlations:**  
- Used by: Trends

**Main Function:**  
- Classify trends into categories (fashion, food, travel, shopping, funny, etc.)
- Enable category-based filtering on Trending Now page

```bash
php artisan make:model TrendCategory -msf
```

**Migration fields:**

```php
Schema::create('trend_categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');              // fashion, food, travel, shopping, funny
    $table->string('slug')->unique();
    $table->string('color')->nullable(); // for UI badge color
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 3. TrendType

**Description:**  
Lookup table for types of trends. Defines whether a trend is based on a hashtag, sound, or topic.

**Correlations:**  
- Used by: Trends

**Main Function:**  
- Distinguish between different trend formats
- Help AI generation understand the nature of the trend

```bash
php artisan make:model TrendType -msf
```

**Migration fields:**

```php
Schema::create('trend_types', function (Blueprint $table) {
    $table->id();
    $table->string('name');              // hashtag, sound, topic
    $table->string('slug')->unique();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 4. HitType

**Description:**  
Lookup table for brand recognition signal types. Defines how a brand mention was detected.

**Correlations:**  
- Used by: BrandRecognitionHits

**Main Function:**  
- Categorize brand mentions by detection method
- Group hits on Brand Recognition page (Tags, Comments, Mentions, Voice, Image)

```bash
php artisan make:model HitType -msf
```

**Migration fields:**

```php
Schema::create('hit_types', function (Blueprint $table) {
    $table->id();
    $table->string('name');              // tag, comment, mention, voice, image
    $table->string('slug')->unique();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 5. Region

**Description:**  
Lookup table for geographic regions. Primarily South African provinces/cities for creator filtering.

**Correlations:**  
- Used by: Creators

**Main Function:**  
- Filter creators by location
- Support SA-focused targeting for campaigns

```bash
php artisan make:model Region -msf
```

**Migration fields:**

```php
Schema::create('regions', function (Blueprint $table) {
    $table->id();
    $table->string('name');              // Gauteng, Western Cape, KwaZulu-Natal, etc.
    $table->string('slug')->unique();
    $table->string('country_code')->default('ZA');
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 6. AssetType

**Description:**  
Lookup table for content asset types. Distinguishes between creator-produced content and studio-produced content.

**Correlations:**  
- Used by: Assets

**Main Function:**  
- Classify assets by production source
- Enable filtering on Top Performing Assets reports

```bash
php artisan make:model AssetType -msf
```

**Migration fields:**

```php
Schema::create('asset_types', function (Blueprint $table) {
    $table->id();
    $table->string('name');              // creator, studio
    $table->string('slug')->unique();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 7. EventType

**Description:**  
Lookup table for tracking event types. Defines the kind of user action being tracked.

**Correlations:**  
- Used by: Events

**Main Function:**  
- Categorize incoming tracking events
- Support different attribution sources (QR scans, UTM clicks, voucher redemptions)

```bash
php artisan make:model EventType -msf
```

**Migration fields:**

```php
Schema::create('event_types', function (Blueprint $table) {
    $table->id();
    $table->string('name');              // qr_scan, utm_click, voucher_redeem
    $table->string('slug')->unique();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 8. Brand

**Description:**  
Core entity representing client brands being monitored. Each brand has default settings for AI generation and serves as the top-level filter for most data.

**Correlations:**  
- Parent of: Campaigns, AiIdeas, BrandRecognitionHits
- Used as global filter on Dashboard

**Main Function:**  
- Store client brand information
- Provide default context (tone, audience) for AI generation
- Act as primary data scope/filter

```bash
php artisan make:model Brand -msf
```

**Migration fields:**

```php
Schema::create('brands', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('sector')->nullable();           // FMCG, Retail, Finance, etc.
    $table->string('default_tone')->nullable();     // playful, professional, edgy
    $table->string('default_audience')->nullable(); // Gen Z, Millennials, etc.
    $table->string('logo')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 9. Creator

**Description:**  
Social media creators/influencers being tracked. Stores profile data and performance metrics from TikTok and Instagram.

**Correlations:**  
- Belongs to: Platform, Region
- Parent of: Assets, Events
- Appears in: Top Creators widget, Reports

**Main Function:**  
- Track creator profiles and follower counts
- Enable creator discovery and filtering
- Link creators to campaign assets
- Support Top Creators ranking

```bash
php artisan make:model Creator -msf
```

**Migration fields:**

```php
Schema::create('creators', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(Platform::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(Region::class)->nullable()->constrained()->nullOnDelete();
    $table->string('handle');
    $table->string('display_name')->nullable();
    $table->text('bio')->nullable();
    $table->string('profile_url')->nullable();
    $table->string('profile_image')->nullable();
    $table->unsignedBigInteger('follower_count')->default(0);
    $table->json('themes')->nullable();             // ["comedy", "lifestyle", "food"]
    $table->json('languages')->nullable();          // ["en", "zu", "af"]
    $table->timestamp('last_refreshed_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['platform_id', 'handle']);
});
```

---

## 10. Campaign

**Description:**  
Marketing campaigns linked to brands. Organizes assets, events, and reporting by campaign scope.

**Correlations:**  
- Belongs to: Brand
- Parent of: Assets, AiIdeas, Events, AggregateDailies

**Main Function:**  
- Group marketing activities under a brand
- Set campaign objectives and timelines
- Scope reporting and asset tracking

```bash
php artisan make:model Campaign -msf
```

**Migration fields:**

```php
Schema::create('campaigns', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(Brand::class)->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('slug');
    $table->text('objective')->nullable();
    $table->text('message')->nullable();
    $table->date('start_date')->nullable();
    $table->date('end_date')->nullable();
    $table->string('status')->default('draft');     // draft, active, paused, completed
    $table->timestamps();

    $table->unique(['brand_id', 'slug']);
});
```

---

## 11. Trend

**Description:**  
Trending topics, sounds, and hashtags from TikTok and Instagram. The core data for the "Trending Now" feature.

**Correlations:**  
- Belongs to: Platform, TrendType, TrendCategory
- Parent of: TrendExamples, AiIdeas

**Main Function:**  
- Store trending content discovered from social platforms
- Provide trend data for AI idea generation
- Power the Trending Now page and Dashboard widget

```bash
php artisan make:model Trend -msf
```

**Migration fields:**

```php
Schema::create('trends', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(Platform::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(TrendType::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(TrendCategory::class)->nullable()->constrained()->nullOnDelete();
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('external_id')->nullable();      // platform's own ID
    $table->string('safety_flag')->default('unknown'); // safe, risky, unknown
    $table->json('raw_source')->nullable();         // original API response
    $table->timestamp('first_seen_at')->nullable();
    $table->timestamp('last_seen_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index(['platform_id', 'external_id']);
});
```

---

## 12. TrendExample

**Description:**  
Example posts that showcase a trend. Provides visual evidence and metrics for each trend.

**Correlations:**  
- Belongs to: Trend

**Main Function:**  
- Store sample content for each trend
- Display thumbnails and metrics on Trending Now page
- Help marketers understand how a trend looks in practice

```bash
php artisan make:model TrendExample -msf
```

**Migration fields:**

```php
Schema::create('trend_examples', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(Trend::class)->constrained()->cascadeOnDelete();
    $table->string('post_url')->nullable();
    $table->string('post_external_id')->nullable();
    $table->string('thumbnail')->nullable();
    $table->json('metrics')->nullable();            // {views, likes, comments, shares}
    $table->timestamp('captured_at')->nullable();
    $table->timestamps();
});
```

---

## 13. AiIdea

**Description:**  
AI-generated content concepts. Stores inputs, outputs, and risk assessments from LLM generation.

**Correlations:**  
- Belongs to: Brand, Campaign (optional), Trend (optional), User

**Main Function:**  
- Store AI-generated content ideas
- Link ideas to trends and brand context
- Support regeneration with same inputs
- Power the AI Recommended page and Dashboard widget

```bash
php artisan make:model AiIdea -msf
```

**Migration fields:**

```php
Schema::create('ai_ideas', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(Brand::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(Campaign::class)->nullable()->constrained()->nullOnDelete();
    $table->foreignIdFor(Trend::class)->nullable()->constrained()->nullOnDelete();
    $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete(); // who generated
    $table->json('inputs')->nullable();             // {tone, audience, objective, message, platform_preference}
    $table->json('output')->nullable();             // {title, idea, hook, script_outline, suggested_sound, platform}
    $table->string('risk_level')->default('low');   // low, medium, high
    $table->text('risk_note')->nullable();
    $table->timestamps();
});
```

---

## 14. Asset

**Description:**  
Content pieces (posts, videos) tied to campaigns. Tracks both creator-produced and studio-produced content.

**Correlations:**  
- Belongs to: Campaign, Creator (optional), Platform, AssetType
- Parent of: Events, AggregateDailies

**Main Function:**  
- Track campaign content performance
- Link assets to creators when applicable
- Power Top Performing Assets widget and reports

```bash
php artisan make:model Asset -msf
```

**Migration fields:**

```php
Schema::create('assets', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(Creator::class)->nullable()->constrained()->nullOnDelete();
    $table->foreignIdFor(Platform::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(AssetType::class)->constrained()->cascadeOnDelete();
    $table->string('title')->nullable();
    $table->string('post_url')->nullable();
    $table->string('post_external_id')->nullable();
    $table->string('thumbnail')->nullable();
    $table->json('metrics')->nullable();            // {views, likes, comments, shares, saves}
    $table->timestamp('published_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 15. BrandRecognitionHit

**Description:**  
Organic brand mentions detected across platforms. Captures evidence of unpaid brand recognition via tags, comments, mentions, voice, or image detection.

**Correlations:**  
- Belongs to: Brand, Platform, HitType
- Reviewed by: User

**Main Function:**  
- Store detected brand mentions
- Track confidence scores for each detection
- Support manual review workflow
- Power Brand Recognition page and Dashboard widget

```bash
php artisan make:model BrandRecognitionHit -msf
```

**Migration fields:**

```php
Schema::create('brand_recognition_hits', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(Brand::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(Platform::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(HitType::class)->constrained()->cascadeOnDelete();
    $table->string('evidence_url')->nullable();
    $table->string('evidence_external_id')->nullable();
    $table->text('snippet')->nullable();            // extracted text or description
    $table->decimal('confidence', 5, 2)->default(0); // 0.00 - 100.00
    $table->string('review_status')->default('pending'); // pending, verified, rejected
    $table->text('review_notes')->nullable();
    $table->foreignIdFor(User::class, 'reviewed_by')->nullable()->constrained()->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();
    $table->timestamp('detected_at')->nullable();
    $table->timestamps();
});
```

---

## 16. Event

**Description:**  
Tracking events from QR scans, UTM links, voucher redemptions, etc. Captures user actions that can be attributed to campaigns.

**Correlations:**  
- Belongs to: EventType, Campaign (optional), Creator (optional), Asset (optional)

**Main Function:**  
- Log attribution events from various sources
- Link actions to campaigns, creators, and assets
- Feed data into daily aggregations
- Support Actions metrics on Dashboard

```bash
php artisan make:model Event -msf
```

**Migration fields:**

```php
Schema::create('events', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(EventType::class)->constrained()->cascadeOnDelete();
    $table->foreignIdFor(Campaign::class)->nullable()->constrained()->nullOnDelete();
    $table->foreignIdFor(Creator::class)->nullable()->constrained()->nullOnDelete();
    $table->foreignIdFor(Asset::class)->nullable()->constrained()->nullOnDelete();
    $table->json('metadata')->nullable();           // {utm_source, utm_medium, utm_campaign, referrer, etc.}
    $table->string('source')->nullable();           // utm, qr, direct
    $table->timestamp('occurred_at');
    $table->timestamps();

    $table->index(['campaign_id', 'occurred_at']);
});
```

---

## 17. AggregateDaily

**Description:**  
Daily rollups of metrics for fast dashboard queries. Pre-computed aggregations to avoid expensive real-time calculations.

**Correlations:**  
- Belongs to: Campaign (optional), Creator (optional), Asset (optional)

**Main Function:**  
- Store pre-computed daily metrics
- Power Dashboard KPIs (Total Reach, Engagement Rate, Actions)
- Enable fast reporting queries
- Support period-based filtering (1w, 2w, 30d, 6m)

```bash
php artisan make:model AggregateDaily -msf
```

**Migration fields:**

```php
Schema::create('aggregate_dailies', function (Blueprint $table) {
    $table->id();
    $table->date('date');
    $table->foreignIdFor(Campaign::class)->nullable()->constrained()->cascadeOnDelete();
    $table->foreignIdFor(Creator::class)->nullable()->constrained()->cascadeOnDelete();
    $table->foreignIdFor(Asset::class)->nullable()->constrained()->cascadeOnDelete();
    $table->json('metrics')->nullable();            // {reach, impressions, engagement, clicks, conversions}
    $table->timestamp('computed_at')->nullable();
    $table->timestamps();

    $table->unique(['date', 'campaign_id', 'creator_id', 'asset_id'], 'aggregate_daily_unique');
    $table->index(['date', 'campaign_id']);
});
```

---

## Quick Run (All Commands)

```bash
php artisan make:model Platform -msf
php artisan make:model TrendCategory -msf
php artisan make:model TrendType -msf
php artisan make:model HitType -msf
php artisan make:model Region -msf
php artisan make:model AssetType -msf
php artisan make:model EventType -msf
php artisan make:model Brand -msf
php artisan make:model Creator -msf
php artisan make:model Campaign -msf
php artisan make:model Trend -msf
php artisan make:model TrendExample -msf
php artisan make:model AiIdea -msf
php artisan make:model Asset -msf
php artisan make:model BrandRecognitionHit -msf
php artisan make:model Event -msf
php artisan make:model AggregateDaily -msf
```

---

## After Creating Models

1. Update each migration file with the fields above
2. Run `php artisan migrate`
3. Add `$fillable` arrays to each model
4. Add relationships to each model
5. Create seeders for lookup tables (Platform, TrendCategory, TrendType, HitType, Region, AssetType, EventType)