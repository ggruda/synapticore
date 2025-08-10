<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\Worklog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Worklog>
 */
class WorklogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 week', 'now');
        $seconds = fake()->numberBetween(300, 14400); // 5 minutes to 4 hours
        $endedAt = (clone $startedAt)->modify("+{$seconds} seconds");

        return [
            'ticket_id' => Ticket::factory(),
            'phase' => fake()->randomElement([
                Worklog::PHASE_PLAN,
                Worklog::PHASE_IMPLEMENT,
                Worklog::PHASE_TEST,
                Worklog::PHASE_REVIEW,
                Worklog::PHASE_PR,
            ]),
            'seconds' => $seconds,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'notes' => fake()->optional(0.3)->paragraph(),
        ];
    }

    /**
     * Indicate that the worklog is for planning.
     */
    public function planning(): static
    {
        return $this->state(fn (array $attributes) => [
            'phase' => Worklog::PHASE_PLAN,
            'seconds' => fake()->numberBetween(1800, 7200), // 30 min to 2 hours
            'notes' => 'Analyzed requirements and created implementation plan',
        ]);
    }

    /**
     * Indicate that the worklog is for implementation.
     */
    public function implementing(): static
    {
        return $this->state(fn (array $attributes) => [
            'phase' => Worklog::PHASE_IMPLEMENT,
            'seconds' => fake()->numberBetween(3600, 14400), // 1 to 4 hours
        ]);
    }

    /**
     * Indicate that the worklog is for testing.
     */
    public function testing(): static
    {
        return $this->state(fn (array $attributes) => [
            'phase' => Worklog::PHASE_TEST,
            'seconds' => fake()->numberBetween(1800, 5400), // 30 min to 1.5 hours
            'notes' => 'Wrote unit tests and performed integration testing',
        ]);
    }
}
