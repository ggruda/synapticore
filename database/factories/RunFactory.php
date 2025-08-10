<?php

namespace Database\Factories;

use App\Models\Run;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Run>
 */
class RunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $status = fake()->randomElement([
            Run::STATUS_PENDING,
            Run::STATUS_RUNNING,
            Run::STATUS_SUCCESS,
            Run::STATUS_FAILED,
            Run::STATUS_SKIPPED,
        ]);

        $hasResults = in_array($status, [Run::STATUS_SUCCESS, Run::STATUS_FAILED]);

        return [
            'ticket_id' => Ticket::factory(),
            'type' => fake()->randomElement([
                Run::TYPE_LINT,
                Run::TYPE_TYPECHECK,
                Run::TYPE_TEST,
                Run::TYPE_BUILD,
                Run::TYPE_REVIEW,
            ]),
            'status' => $status,
            'junit_path' => $hasResults && fake()->boolean(60) ?
                'storage/tests/'.fake()->uuid().'/junit.xml' : null,
            'coverage_path' => $hasResults && fake()->boolean(40) ?
                'storage/tests/'.fake()->uuid().'/coverage.xml' : null,
            'logs_path' => $hasResults ?
                'storage/logs/'.fake()->uuid().'.log' : null,
        ];
    }

    /**
     * Indicate that the run was successful.
     */
    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Run::STATUS_SUCCESS,
            'logs_path' => 'storage/logs/'.fake()->uuid().'.log',
        ]);
    }

    /**
     * Indicate that the run failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Run::STATUS_FAILED,
            'logs_path' => 'storage/logs/'.fake()->uuid().'.log',
        ]);
    }

    /**
     * Indicate that the run is for tests with coverage.
     */
    public function testWithCoverage(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Run::TYPE_TEST,
            'status' => Run::STATUS_SUCCESS,
            'junit_path' => 'storage/tests/'.fake()->uuid().'/junit.xml',
            'coverage_path' => 'storage/tests/'.fake()->uuid().'/coverage.xml',
            'logs_path' => 'storage/logs/'.fake()->uuid().'.log',
        ]);
    }
}
