<?php

namespace Database\Factories;

use App\Models\NotificationLog;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NotificationLog> */
class NotificationLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'type' => 'trial_ending_3d',
            'dedupe_key' => 'trial_ending_3d:'.Str::ulid(),
            'channel' => 'mail',
            'sent_at' => now(),
        ];
    }
}
