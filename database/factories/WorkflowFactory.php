<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'state' => fake()->randomElement([
                Workflow::STATE_INGESTED,
                Workflow::STATE_CONTEXT_READY,
                Workflow::STATE_PLANNED,
                Workflow::STATE_IMPLEMENTING,
                Workflow::STATE_TESTING,
                Workflow::STATE_REVIEWING,
                Workflow::STATE_FIXING,
                Workflow::STATE_PR_CREATED,
                Workflow::STATE_DONE,
                Workflow::STATE_FAILED,
            ]),
            'retries' => fake()->numberBetween(0, 3),
        ];
    }

    /**
     * Indicate that the workflow is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => fake()->randomElement([
                Workflow::STATE_PLANNED,
                Workflow::STATE_IMPLEMENTING,
                Workflow::STATE_TESTING,
                Workflow::STATE_REVIEWING,
            ]),
        ]);
    }

    /**
     * Indicate that the workflow is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => Workflow::STATE_DONE,
            'retries' => 0,
        ]);
    }

    /**
     * Indicate that the workflow has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => Workflow::STATE_FAILED,
            'retries' => 3,
        ]);
    }
}
