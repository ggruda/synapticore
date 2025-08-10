<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $descriptions = [
            'Backend Development - API Implementation',
            'Frontend Development - React Components',
            'Database Optimization and Migration',
            'Code Review and Refactoring',
            'Bug Fixes and Maintenance',
            'Feature Development - User Authentication',
            'Testing and Quality Assurance',
            'DevOps and CI/CD Setup',
            'Documentation and Technical Writing',
            'Project Management and Coordination',
        ];

        $seconds = fake()->randomElement([
            1800,   // 30 minutes
            3600,   // 1 hour
            5400,   // 1.5 hours
            7200,   // 2 hours
            10800,  // 3 hours
            14400,  // 4 hours
            21600,  // 6 hours
            28800,  // 8 hours
        ]);

        $unitPrice = fake()->randomElement([75, 100, 125, 150, 175, 200]); // Hourly rates
        $hours = $seconds / 3600;
        $netAmount = round($hours * $unitPrice, 2);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->randomElement($descriptions),
            'seconds' => $seconds,
            'unit_price' => $unitPrice,
            'net_amount' => $netAmount,
        ];
    }

    /**
     * Indicate that the item is for development work.
     */
    public function development(): static
    {
        return $this->state(function (array $attributes) {
            $seconds = fake()->numberBetween(14400, 57600); // 4 to 16 hours
            $unitPrice = fake()->randomElement([125, 150, 175]);
            $hours = $seconds / 3600;

            return [
                'description' => 'Software Development - '.fake()->randomElement(['Feature Implementation', 'API Development', 'Frontend Components']),
                'seconds' => $seconds,
                'unit_price' => $unitPrice,
                'net_amount' => round($hours * $unitPrice, 2),
            ];
        });
    }

    /**
     * Indicate that the item is for consulting.
     */
    public function consulting(): static
    {
        return $this->state(function (array $attributes) {
            $seconds = fake()->numberBetween(3600, 14400); // 1 to 4 hours
            $unitPrice = fake()->randomElement([200, 250, 300]);
            $hours = $seconds / 3600;

            return [
                'description' => 'Technical Consulting - '.fake()->randomElement(['Architecture Review', 'Code Audit', 'Performance Analysis']),
                'seconds' => $seconds,
                'unit_price' => $unitPrice,
                'net_amount' => round($hours * $unitPrice, 2),
            ];
        });
    }
}
