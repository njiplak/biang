<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Announcement extends Model
{
    /** @use HasFactory<\Database\Factories\AnnouncementFactory> */
    use HasFactory;

    protected $fillable = [
        'ulid', 'title', 'body', 'audience', 'audience_filter',
        'severity', 'is_dismissible', 'published_at', 'expires_at',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'audience_filter' => 'array',
            'is_dismissible' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->ulid ??= (string) Str::ulid());
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * The row-level counterpart to scopeLive, for a model already in memory.
     * Derived from the clock every time it is asked, because a stored flag
     * would be wrong the moment `expires_at` passed.
     */
    public function isLive(): bool
    {
        if ($this->published_at === null || $this->published_at->isFuture()) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function dismissals(): HasMany
    {
        return $this->hasMany(AnnouncementDismissal::class);
    }
}
