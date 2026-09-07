<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnnouncementDismissal> */
class AnnouncementDismissalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'announcement_id' => Announcement::factory(),
            'user_id' => User::factory(),
            'dismissed_at' => now(),
        ];
    }
}
