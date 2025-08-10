<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $projectNames = ['Web Platform', 'Mobile App', 'API Gateway', 'Analytics Dashboard', 'E-Commerce Portal'];

        return [
            'name' => fake()->randomElement($projectNames).' '.fake()->company(),
            'repo_urls' => [
                'https://github.com/'.fake()->userName().'/'.fake()->slug(),
                'https://gitlab.com/'.fake()->userName().'/'.fake()->slug(),
            ],
            'default_branch' => fake()->randomElement(['main', 'master', 'develop']),
            'allowed_paths' => [
                'src/',
                'app/',
                'lib/',
                'packages/',
                'tests/',
            ],
            'language_profile' => [
                'primary' => fake()->randomElement(['php', 'javascript', 'typescript', 'python', 'java']),
                'frameworks' => fake()->randomElements(['laravel', 'react', 'vue', 'django', 'spring'], 2),
                'test_framework' => fake()->randomElement(['phpunit', 'jest', 'pytest', 'junit']),
            ],
            'provider_overrides' => fake()->boolean(30) ? [
                'github' => ['token' => 'ghp_'.fake()->regexify('[A-Za-z0-9]{36}')],
            ] : null,
        ];
    }
}
