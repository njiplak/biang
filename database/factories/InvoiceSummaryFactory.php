<?php

namespace Database\Factories;

use App\Models\InvoiceSummary;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceSummary> */
class InvoiceSummaryFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(900, 19900);
        $tax = (int) round($subtotal * 0.11);

        return [
            'workspace_id' => Workspace::factory(),
            'dodo_invoice_id' => 'inv_'.fake()->unique()->bothify('??##########'),
            'number' => 'INV-'.fake()->unique()->numberBetween(1000, 999999),
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $subtotal + $tax,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issued_at' => now(),
            'paid_at' => now(),
            'hosted_url' => fake()->url(),
        ];
    }
}
