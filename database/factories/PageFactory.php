<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Page> */
class PageFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'body' => fake()->paragraphs(3, true),
            // Draft by default, matching the table: publishing is deliberate.
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['published_at' => now()->subDay()]);
    }

    /** Published, but not yet - the "goes live at midnight" case. */
    public function scheduled(): static
    {
        return $this->state(fn () => ['published_at' => now()->addDay()]);
    }
}
