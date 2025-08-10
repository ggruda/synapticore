<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Secret;
use App\Models\Ticket;
use App\Models\User;
use App\Services\WorkflowOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

/**
 * Test command for Admin UI and API functionality.
 */
class TestAdminApi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:test
                            {--create-user : Create test admin user}
                            {--test-api : Test API endpoints}
                            {--start-workflow : Start a workflow via API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Admin UI and API functionality';

    /**
     * Execute the console command.
     */
    public function handle(WorkflowOrchestrator $orchestrator): int
    {
        $this->info('🧪 Testing Admin & API Surface');
        $this->newLine();

        if ($this->option('create-user')) {
            $this->createTestAdminUser();
        }

        if ($this->option('test-api')) {
            $this->testApiEndpoints();
        }

        if ($this->option('start-workflow')) {
            $this->startWorkflowViaApi();
        }

        if (! $this->option('create-user') && ! $this->option('test-api') && ! $this->option('start-workflow')) {
            $this->displaySystemStatus($orchestrator);
        }

        return Command::SUCCESS;
    }

    /**
     * Create test admin user.
     */
    private function createTestAdminUser(): void
    {
        $this->info('Creating test admin user...');

        // Create permissions if they don't exist
        $permissions = [
            'manage projects',
            'manage tickets', 
            'manage workflows',
            'view artifacts',
        ];

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission]);
        }

        // Create admin role if it doesn't exist
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo($permissions);

        // Create or update test admin user
        $user = User::updateOrCreate(
            ['email' => 'admin@synapticore.test'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
            ]
        );

        $user->assignRole('admin');

        // Create API token
        $token = $user->createToken('admin-api-token')->plainTextToken;

        $this->info('✅ Admin user created successfully!');
        $this->table(
            ['Field', 'Value'],
            [
                ['Email', 'admin@synapticore.test'],
                ['Password', 'password'],
                ['Role', 'admin'],
                ['API Token', $token],
            ]
        );

        $this->newLine();
        $this->info('Admin UI URLs:');
        $this->line('Dashboard: '.url('/admin'));
        $this->line('Projects: '.url('/admin/projects'));
        $this->line('Tickets: '.url('/admin/tickets'));
    }

    /**
     * Test API endpoints.
     */
    private function testApiEndpoints(): void
    {
        $this->info('Testing API endpoints...');

        // Get or create test project
        $project = $this->getOrCreateTestProject();

        if (! $project) {
            $this->error('Failed to create test project');

            return;
        }

        // Get API key
        $apiKeySecret = $project->secrets()->where('key_id', 'api_key')->first();
        $apiKey = $apiKeySecret ? decrypt($apiKeySecret->payload) : null;

        if (! $apiKey) {
            $this->error('No API key found for project');

            return;
        }

        $this->info("Using API key: {$apiKey}");
        $this->newLine();

        $baseUrl = config('app.url').'/api';

        // Test endpoints
        $endpoints = [
            [
                'name' => 'Project Details',
                'method' => 'GET',
                'url' => "{$baseUrl}/projects/{$project->id}",
            ],
            [
                'name' => 'Workflow Statistics',
                'method' => 'GET',
                'url' => "{$baseUrl}/workflows/statistics",
            ],
            [
                'name' => 'List Workflows',
                'method' => 'GET',
                'url' => "{$baseUrl}/workflows?project_id={$project->id}",
            ],
        ];

        foreach ($endpoints as $endpoint) {
            $this->testEndpoint($endpoint, $apiKey);
        }
    }

    /**
     * Start workflow via API.
     */
    private function startWorkflowViaApi(): void
    {
        $this->info('Starting workflow via API...');

        // Get or create test project
        $project = $this->getOrCreateTestProject();

        if (! $project) {
            $this->error('Failed to create test project');

            return;
        }

        // Get API key
        $apiKeySecret = $project->secrets()->where('key_id', 'api_key')->first();
        $apiKey = $apiKeySecret ? decrypt($apiKeySecret->payload) : null;

        if (! $apiKey) {
            $this->error('No API key found for project');

            return;
        }

        $baseUrl = config('app.url').'/api';

        // Prepare ticket data
        $ticketData = [
            'project_id' => $project->id,
            'external_key' => 'API-'.rand(1000, 9999),
            'title' => 'Test ticket created via API',
            'body' => 'This is a test ticket created through the API to demonstrate workflow initiation.',
            'acceptance_criteria' => [
                'Workflow starts successfully',
                'Status can be queried',
                'Artifacts are generated',
            ],
            'labels' => ['api-test', 'demo'],
            'priority' => 'medium',
            'source' => 'jira',
        ];

        $this->info('Sending API request to start workflow...');
        $this->table(['Field', 'Value'], collect($ticketData)->map(function ($value, $key) {
            return [$key, is_array($value) ? json_encode($value) : $value];
        })->toArray());

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])->post("{$baseUrl}/workflows/start", $ticketData);

            if ($response->successful()) {
                $data = $response->json();
                $this->info('✅ Workflow started successfully!');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Ticket ID', $data['data']['ticket_id'] ?? 'N/A'],
                        ['Workflow ID', $data['data']['workflow_id'] ?? 'N/A'],
                        ['State', $data['data']['state'] ?? 'N/A'],
                        ['External Key', $data['data']['external_key'] ?? 'N/A'],
                    ]
                );

                // Query status
                if (isset($data['data']['workflow_id'])) {
                    $this->newLine();
                    $this->info('Querying workflow status...');

                    $statusResponse = Http::withHeaders([
                        'Authorization' => 'Bearer '.$apiKey,
                        'Accept' => 'application/json',
                    ])->get("{$baseUrl}/workflows/{$data['data']['workflow_id']}/status");

                    if ($statusResponse->successful()) {
                        $status = $statusResponse->json()['data'];
                        $this->table(
                            ['Field', 'Value'],
                            [
                                ['Current State', $status['current_state']],
                                ['Is Complete', $status['is_complete'] ? 'Yes' : 'No'],
                                ['Has Plan', $status['has_plan'] ? 'Yes' : 'No'],
                                ['Has Patch', $status['has_patch'] ? 'Yes' : 'No'],
                                ['Has PR', $status['has_pr'] ? 'Yes' : 'No'],
                            ]
                        );
                    }
                }
            } else {
                $this->error('Failed to start workflow');
                $this->line('Response: '.$response->body());
            }
        } catch (\Exception $e) {
            $this->error('API request failed: '.$e->getMessage());
        }
    }

    /**
     * Display system status.
     */
    private function displaySystemStatus(WorkflowOrchestrator $orchestrator): void
    {
        $stats = $orchestrator->getStatistics();

        $this->info('📊 System Status');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Workflows', $stats['total_workflows']],
                ['Completed', $stats['completed']],
                ['Failed', $stats['failed']],
                ['In Progress', $stats['in_progress']],
                ['Success Rate', $stats['success_rate'].'%'],
                ['Avg Duration', $stats['average_duration_minutes'].' minutes'],
            ]
        );

        $this->newLine();
        $this->info('Available Commands:');
        $this->line('  php artisan admin:test --create-user    Create admin user');
        $this->line('  php artisan admin:test --test-api       Test API endpoints');
        $this->line('  php artisan admin:test --start-workflow Start workflow via API');

        $this->newLine();
        $this->info('Admin UI: '.url('/admin'));
        $this->info('API Docs: '.url('/api/documentation'));
    }

    /**
     * Get or create test project.
     */
    private function getOrCreateTestProject(): ?Project
    {
        $project = Project::where('name', 'API Test Project')->first();

        if (! $project) {
            $this->info('Creating test project...');

            $project = Project::create([
                'name' => 'API Test Project',
                'repo_urls' => ['https://github.com/laravel/laravel'],
                'default_branch' => 'main',
                'allowed_paths' => ['app/', 'tests/'],
                'language_profile' => [
                    'languages' => ['php'],
                    'commands' => [
                        'lint' => 'vendor/bin/pint --test',
                        'test' => 'vendor/bin/phpunit',
                    ],
                ],
            ]);

            // Generate API key
            Secret::create([
                'project_id' => $project->id,
                'kind' => 'api',
                'key_id' => 'api_key',
                'payload' => encrypt('sk_test_'.\Illuminate\Support\Str::random(32)),
                'meta' => ['type' => 'api_key'],
            ]);

            Secret::create([
                'project_id' => $project->id,
                'kind' => 'webhook',
                'key_id' => 'webhook_secret',
                'payload' => encrypt(\Illuminate\Support\Str::random(32)),
                'meta' => ['type' => 'webhook_secret'],
            ]);
        }

        return $project;
    }

    /**
     * Test an API endpoint.
     */
    private function testEndpoint(array $endpoint, string $apiKey): void
    {
        $this->info("Testing: {$endpoint['name']}");

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])->send($endpoint['method'], $endpoint['url']);

            if ($response->successful()) {
                $this->info("  ✅ {$endpoint['method']} {$endpoint['url']} - Status: {$response->status()}");

                if ($response->json() && isset($response->json()['success'])) {
                    $this->line('  Response: '.json_encode($response->json()['data'] ?? [], JSON_PRETTY_PRINT));
                }
            } else {
                $this->error("  ❌ {$endpoint['method']} {$endpoint['url']} - Status: {$response->status()}");
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Failed: '.$e->getMessage());
        }

        $this->newLine();
    }
}
