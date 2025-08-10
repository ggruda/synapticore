<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $source = fake()->randomElement(['jira', 'linear', 'azure']);
        $prefixes = [
            'jira' => 'PROJ-',
            'linear' => 'LIN-',
            'azure' => 'AZ-',
        ];

        return [
            'project_id' => Project::factory(),
            'external_key' => $prefixes[$source].fake()->numberBetween(1000, 9999),
            'source' => $source,
            'title' => fake()->sentence(6),
            'body' => fake()->paragraphs(3, true),
            'acceptance_criteria' => [
                'criteria' => fake()->sentences(3),
                'definition_of_done' => fake()->sentences(2),
            ],
            'labels' => fake()->randomElements(['bug', 'feature', 'enhancement', 'documentation', 'refactor'], 2),
            'status' => fake()->randomElement(['open', 'in_progress', 'review', 'done', 'blocked']),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'meta' => [
                'story_points' => fake()->randomElement([1, 2, 3, 5, 8, 13]),
                'sprint' => 'Sprint '.fake()->numberBetween(1, 20),
                'assignee' => fake()->email(),
                'reporter' => fake()->email(),
            ],
        ];
    }
}
