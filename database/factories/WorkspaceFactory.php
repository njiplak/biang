<?php

namespace Database\Factories;

use App\Enums\AccessStatus;
use App\Enums\BillingStatus;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Workspace> */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1, 999999))->toString(),
            'name' => $name,
            'billing_status' => BillingStatus::Free,
            'access_status' => AccessStatus::Active,
            'settings' => [],
        ];
    }

    public function overLimit(array $features = ['seats']): static
    {
        return $this->state(fn () => [
            'over_limit_at' => now(),
            'over_limit_features' => $features,
        ]);
    }

    public function suspended(string $reason = 'Abuse investigation'): static
    {
        return $this->state(fn () => [
            'access_status' => AccessStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
    }

    public function trialing(): static
    {
        return $this->state(fn () => ['billing_status' => BillingStatus::Trialing]);
    }

    public function paying(): static
    {
        return $this->state(fn () => ['billing_status' => BillingStatus::Active]);
    }
}
