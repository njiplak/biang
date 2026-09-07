<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per person, not per workspace: one human sees an announcement once. */
class AnnouncementDismissal extends Model
{
    /** @use HasFactory<\Database\Factories\AnnouncementDismissalFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['announcement_id', 'user_id', 'dismissed_at'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
