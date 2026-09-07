<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'audience' => 'all',
            'severity' => 'info',
            'is_dismissible' => true,
            'published_at' => now(),
            'created_by_admin_id' => AdminUser::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['published_at' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
