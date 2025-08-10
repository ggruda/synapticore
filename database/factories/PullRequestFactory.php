<?php

namespace Database\Factories;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PullRequest>
 */
class PullRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = fake()->randomElement(['github', 'gitlab', 'bitbucket']);
        $username = fake()->userName();
        $repo = fake()->slug();
        $prNumber = fake()->numberBetween(1, 1000);

        $urls = [
            'github' => "https://github.com/{$username}/{$repo}/pull/{$prNumber}",
            'gitlab' => "https://gitlab.com/{$username}/{$repo}/-/merge_requests/{$prNumber}",
            'bitbucket' => "https://bitbucket.org/{$username}/{$repo}/pull-requests/{$prNumber}",
        ];

        return [
            'ticket_id' => Ticket::factory(),
            'provider_id' => (string) $prNumber,
            'url' => $urls[$provider],
            'branch_name' => fake()->randomElement(['feature/', 'fix/', 'hotfix/']).fake()->slug(),
            'is_draft' => fake()->boolean(40),
            'labels' => fake()->randomElements(['ready-for-review', 'work-in-progress', 'needs-tests', 'documentation'], 2),
        ];
    }

    /**
     * Indicate that the pull request is ready for review.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_draft' => false,
            'labels' => ['ready-for-review'],
        ]);
    }

    /**
     * Indicate that the pull request is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_draft' => true,
            'labels' => ['work-in-progress'],
        ]);
    }
}
