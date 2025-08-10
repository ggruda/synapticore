<?php

namespace Database\Factories;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Patch>
 */
class PatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $files = [];
        $fileCount = fake()->numberBetween(1, 5);

        for ($i = 0; $i < $fileCount; $i++) {
            $files[] = fake()->randomElement(['app/', 'src/', 'lib/']).
                      fake()->randomElement(['Models/', 'Controllers/', 'Services/']).
                      fake()->word().'.php';
        }

        return [
            'ticket_id' => Ticket::factory(),
            'files_touched' => $files,
            'diff_stats' => [
                'additions' => fake()->numberBetween(10, 500),
                'deletions' => fake()->numberBetween(5, 200),
                'files_changed' => count($files),
            ],
            'risk_score' => fake()->numberBetween(1, 100),
            'summary' => [
                'description' => fake()->sentence(),
                'breaking_changes' => fake()->boolean(20),
                'requires_migration' => fake()->boolean(30),
                'test_coverage' => fake()->randomFloat(2, 60, 100),
            ],
        ];
    }

    /**
     * Indicate that the patch is low risk.
     */
    public function lowRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk_score' => fake()->numberBetween(1, 30),
            'files_touched' => [fake()->randomElement(['app/Models/', 'tests/']).fake()->word().'.php'],
            'diff_stats' => [
                'additions' => fake()->numberBetween(5, 50),
                'deletions' => fake()->numberBetween(0, 20),
                'files_changed' => 1,
            ],
        ]);
    }

    /**
     * Indicate that the patch is high risk.
     */
    public function highRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk_score' => fake()->numberBetween(70, 100),
            'summary' => array_merge($attributes['summary'] ?? [], [
                'breaking_changes' => true,
                'requires_migration' => true,
            ]),
        ]);
    }
}
