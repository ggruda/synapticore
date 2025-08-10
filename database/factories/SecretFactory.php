<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Secret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Secret>
 */
class SecretFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kind = fake()->randomElement([
            Secret::KIND_JIRA,
            Secret::KIND_GITHUB,
            Secret::KIND_GITLAB,
            Secret::KIND_BITBUCKET,
            Secret::KIND_LINEAR,
            Secret::KIND_AZURE,
        ]);

        $payloads = [
            Secret::KIND_JIRA => [
                'url' => 'https://'.fake()->domainWord().'.atlassian.net',
                'email' => fake()->email(),
                'api_token' => fake()->regexify('[A-Za-z0-9]{24}'),
            ],
            Secret::KIND_GITHUB => [
                'token' => 'ghp_'.fake()->regexify('[A-Za-z0-9]{36}'),
            ],
            Secret::KIND_GITLAB => [
                'token' => 'glpat-'.fake()->regexify('[A-Za-z0-9]{20}'),
            ],
            Secret::KIND_BITBUCKET => [
                'username' => fake()->userName(),
                'app_password' => fake()->regexify('[A-Za-z0-9]{20}'),
            ],
            Secret::KIND_LINEAR => [
                'api_key' => 'lin_api_'.fake()->regexify('[A-Za-z0-9]{32}'),
            ],
            Secret::KIND_AZURE => [
                'organization' => fake()->company(),
                'pat' => fake()->regexify('[A-Za-z0-9]{52}'),
            ],
        ];

        return [
            'project_id' => Project::factory(),
            'kind' => $kind,
            'key_id' => $kind.'_'.fake()->uuid(),
            'meta' => [
                'created_by' => fake()->email(),
                'environment' => fake()->randomElement(['development', 'staging', 'production']),
                'expires_at' => fake()->optional()->dateTimeBetween('now', '+1 year'),
            ],
            'payload' => json_encode($payloads[$kind]),
        ];
    }

    /**
     * Indicate that the secret is for GitHub.
     */
    public function github(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => Secret::KIND_GITHUB,
            'key_id' => 'github_'.fake()->uuid(),
            'payload' => json_encode([
                'token' => 'ghp_'.fake()->regexify('[A-Za-z0-9]{36}'),
            ]),
        ]);
    }

    /**
     * Indicate that the secret is for Jira.
     */
    public function jira(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => Secret::KIND_JIRA,
            'key_id' => 'jira_'.fake()->uuid(),
            'payload' => json_encode([
                'url' => 'https://'.fake()->domainWord().'.atlassian.net',
                'email' => fake()->email(),
                'api_token' => fake()->regexify('[A-Za-z0-9]{24}'),
            ]),
        ]);
    }
}
