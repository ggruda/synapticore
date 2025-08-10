<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\TicketProviderContract;
use App\Contracts\VcsProviderContract;
use App\DTO\PlanJson;
use App\Models\Project;
use App\Models\Ticket;
use App\Services\Resolution\ProviderResolver;
use App\Services\Tickets\TicketCommentFormatter;
use Illuminate\Console\Command;

/**
 * Test command for provider resolver and plan comments.
 */
class TestProviderResolver extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resolver:test
                            {--test-overrides : Test project-specific provider overrides}
                            {--test-comment : Test plan comment formatting and posting}
                            {--project-id= : Project ID to test with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test provider resolver and plan comment functionality';

    /**
     * Execute the console command.
     */
    public function handle(ProviderResolver $resolver): int
    {
        $this->info('🔄 Testing Provider Resolver & Plan Comments');
        $this->newLine();

        if ($this->option('test-overrides')) {
            $this->testProviderOverrides($resolver);
        }

        if ($this->option('test-comment')) {
            $this->testPlanComment($resolver);
        }

        if (! $this->option('test-overrides') && ! $this->option('test-comment')) {
            $this->testProviderOverrides($resolver);
            $this->newLine();
            $this->testPlanComment($resolver);
        }

        return Command::SUCCESS;
    }

    /**
     * Test provider overrides functionality.
     */
    private function testProviderOverrides(ProviderResolver $resolver): void
    {
        $this->info('Testing Provider Overrides');
        $this->newLine();

        // Get or create test projects
        $project1 = $this->getOrCreateProject(
            'GitHub + OpenAI Project',
            [
                'ticket_provider' => 'jira',
                'vcs_provider' => 'github',
                'ai' => [
                    'planner' => 'openai',
                    'implement' => 'openai',
                    'review' => 'openai',
                ],
                'embeddings' => 'pgvector',
            ]
        );

        $project2 = $this->getOrCreateProject(
            'GitLab + Claude Project',
            [
                'ticket_provider' => 'linear',
                'vcs_provider' => 'gitlab',
                'ai' => [
                    'planner' => 'anthropic',
                    'implement' => 'anthropic',
                    'review' => 'anthropic',
                ],
                'embeddings' => 'pinecone',
            ]
        );

        // Test resolution for each project
        $this->testProjectProviders($resolver, $project1);
        $this->newLine();
        $this->testProjectProviders($resolver, $project2);
    }

    /**
     * Test provider resolution for a project.
     */
    private function testProjectProviders(ProviderResolver $resolver, Project $project): void
    {
        $this->info("Project: {$project->name}");
        $this->table(['Contract', 'Resolved Provider', 'Status'], [
            $this->testProviderResolution($resolver, $project, TicketProviderContract::class),
            $this->testProviderResolution($resolver, $project, VcsProviderContract::class),
        ]);
    }

    /**
     * Test resolution of a specific provider.
     */
    private function testProviderResolution(
        ProviderResolver $resolver,
        Project $project,
        string $contract
    ): array {
        try {
            $provider = $resolver->resolveForProject($project, $contract);
            $providerClass = get_class($provider);
            $providerName = class_basename($providerClass);

            return [
                class_basename($contract),
                $providerName,
                '✅ Resolved',
            ];
        } catch (\Exception $e) {
            return [
                class_basename($contract),
                'Error: '.$e->getMessage(),
                '❌ Failed',
            ];
        }
    }

    /**
     * Test plan comment formatting and posting.
     */
    private function testPlanComment(ProviderResolver $resolver): void
    {
        $this->info('Testing Plan Comment Formatting');
        $this->newLine();

        // Create test plan data
        $planJson = new PlanJson(
            steps: [
                [
                    'intent' => 'Create authentication middleware',
                    'description' => 'Add JWT authentication middleware to validate tokens',
                    'targets' => ['app/Http/Middleware/JwtAuth.php'],
                    'rationale' => 'Secure API endpoints with token validation',
                    'acceptanceCriteria' => [
                        'Middleware validates JWT tokens',
                        'Invalid tokens return 401 response',
                        'Token expiry is checked',
                    ],
                    'riskFactors' => ['authentication_change'],
                ],
                [
                    'intent' => 'Add login endpoint',
                    'description' => 'Create login endpoint that returns JWT token',
                    'targets' => ['app/Http/Controllers/AuthController.php'],
                    'rationale' => 'Allow users to authenticate and receive tokens',
                    'acceptanceCriteria' => [
                        'Login accepts email/password',
                        'Returns JWT on success',
                        'Returns error on invalid credentials',
                    ],
                    'riskFactors' => [],
                ],
            ],
            testStrategy: 'Unit tests for middleware and controller, integration tests for auth flow',
            risk: 'medium',
            estimatedHours: 4.5,
            dependencies: [
                ['name' => 'firebase/php-jwt', 'version' => '^6.0', 'type' => 'composer'],
            ],
            filesAffected: [
                'app/Http/Middleware/JwtAuth.php',
                'app/Http/Controllers/AuthController.php',
                'routes/api.php',
                'config/jwt.php',
            ],
            breakingChanges: [],
            summary: 'Implement user authentication with JWT tokens',
            metadata: ['framework' => 'laravel', 'version' => '12.0', 'references' => [
                ['title' => 'JWT Best Practices', 'url' => 'https://tools.ietf.org/html/rfc8725'],
            ]],
        );

        // Format the comment
        $formatter = new TicketCommentFormatter;
        $markdown = $formatter->formatPlanComment($planJson, 'WF-123');

        $this->info('Formatted Markdown:');
        $this->line($markdown);
        $this->newLine();

        // Test actual posting if project ID provided
        $projectId = $this->option('project-id');
        if ($projectId) {
            $this->testActualPosting($resolver, (int) $projectId, $markdown);
        } else {
            $this->warn('Provide --project-id=N to test actual comment posting');
        }
    }

    /**
     * Test actual comment posting to ticket provider.
     */
    private function testActualPosting(ProviderResolver $resolver, int $projectId, string $markdown): void
    {
        $project = Project::find($projectId);
        if (! $project) {
            $this->error("Project {$projectId} not found");

            return;
        }

        // Get ticket
        $ticket = $project->tickets()->first();
        if (! $ticket) {
            // Create test ticket
            $ticket = Ticket::create([
                'project_id' => $project->id,
                'external_key' => 'TEST-'.rand(1000, 9999),
                'source' => 'jira',
                'title' => 'Test ticket for plan comment',
                'body' => 'This is a test ticket to demonstrate plan comments',
                'acceptance_criteria' => ['Comment is posted successfully'],
                'labels' => ['test'],
                'status' => 'in_progress',
                'priority' => 'medium',
                'meta' => ['test' => true],
            ]);
        }

        $this->info("Posting comment to ticket: {$ticket->external_key}");

        try {
            // Resolve ticket provider for this project
            $ticketProvider = $resolver->resolveForProject($project, TicketProviderContract::class);

            // Post the comment
            $ticketProvider->addComment($ticket->external_key, $markdown);

            $this->info('✅ Comment posted successfully!');
            $this->info("Check your ticket system for ticket: {$ticket->external_key}");

        } catch (\Exception $e) {
            $this->error('❌ Failed to post comment: '.$e->getMessage());

            if ($e->getMessage() === 'JiraTicketProvider::addComment() not yet implemented') {
                $this->warn('Note: Jira integration requires valid credentials in config/services.php');
            }
        }
    }

    /**
     * Get or create a test project.
     */
    private function getOrCreateProject(string $name, array $overrides): Project
    {
        $project = Project::where('name', $name)->first();

        if (! $project) {
            $project = Project::create([
                'name' => $name,
                'repo_urls' => ['https://github.com/example/repo'],
                'default_branch' => 'main',
                'allowed_paths' => ['app/', 'src/'],
                'language_profile' => ['languages' => ['php', 'javascript']],
                'provider_overrides' => $overrides,
            ]);

            $this->info("Created project: {$name}");
        } else {
            // Update overrides
            $project->update(['provider_overrides' => $overrides]);
            $this->info("Updated project: {$name}");
        }

        return $project;
    }
}
