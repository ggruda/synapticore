<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Repo>
 */
class RepoFactory extends Factory
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
        $repoName = fake()->slug();

        $urls = [
            'github' => "https://github.com/{$username}/{$repoName}.git",
            'gitlab' => "https://gitlab.com/{$username}/{$repoName}.git",
            'bitbucket' => "https://bitbucket.org/{$username}/{$repoName}.git",
        ];

        return [
            'project_id' => Project::factory(),
            'provider' => $provider,
            'remote_url' => $urls[$provider],
            'default_branch' => fake()->randomElement(['main', 'master', 'develop']),
        ];
    }
}
