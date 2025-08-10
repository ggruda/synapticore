<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Command to test Jira webhook integration.
 */
class TestJiraWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhook:test-jira 
                            {--project=1 : Project ID to use}
                            {--key=TEST-123 : Issue key}
                            {--event=jira:issue_created : Webhook event type}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Jira webhook by sending a mock payload';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $projectId = $this->option('project');
        $issueKey = $this->option('key');
        $event = $this->option('event');

        // Ensure project exists
        $project = Project::find($projectId);
        if (! $project) {
            $this->error("Project with ID {$projectId} not found.");

            return Command::FAILURE;
        }

        $this->info("Testing Jira webhook for project: {$project->name}");
        $this->info("Issue key: {$issueKey}");
        $this->info("Event: {$event}");

        // Create mock Jira webhook payload
        $payload = $this->createMockPayload($issueKey, $event);

        // Send webhook to local endpoint
        // Use nginx container hostname when running in Docker
        $baseUrl = app()->runningInConsole() && env('APP_ENV') === 'local'
            ? 'http://nginx'
            : config('app.url');
        $url = $baseUrl."/api/webhooks/jira/{$projectId}";

        $this->info("Sending webhook to: {$url}");

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Atlassian-Webhook-Identifier' => 'test-webhook',
            ])->post($url, $payload);

            if ($response->successful()) {
                $this->info('✅ Webhook sent successfully!');
                $this->info('Response: '.$response->body());

                // Check if ticket was created
                $ticket = \App\Models\Ticket::where('external_key', $issueKey)
                    ->where('project_id', $projectId)
                    ->first();

                if ($ticket) {
                    $this->info("✅ Ticket created/updated: ID {$ticket->id}");
                    $this->table(
                        ['Field', 'Value'],
                        [
                            ['ID', $ticket->id],
                            ['External Key', $ticket->external_key],
                            ['Title', $ticket->title],
                            ['Status', $ticket->status],
                            ['Priority', $ticket->priority],
                            ['Workflow State', $ticket->workflow?->state ?? 'N/A'],
                        ]
                    );
                }
            } else {
                $this->error('❌ Webhook failed: '.$response->status());
                $this->error('Response: '.$response->body());
            }
        } catch (\Exception $e) {
            $this->error('❌ Error sending webhook: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Create a mock Jira webhook payload.
     */
    private function createMockPayload(string $issueKey, string $event): array
    {
        return [
            'timestamp' => time() * 1000,
            'webhookEvent' => $event,
            'issue_event_type_name' => str_replace('jira:issue_', '', $event),
            'user' => [
                'self' => 'https://example.atlassian.net/rest/api/2/user?accountId=123',
                'accountId' => '123',
                'emailAddress' => 'test@example.com',
                'displayName' => 'Test User',
                'active' => true,
            ],
            'issue' => [
                'id' => '10001',
                'self' => 'https://example.atlassian.net/rest/api/2/issue/10001',
                'key' => $issueKey,
                'fields' => [
                    'issuetype' => [
                        'id' => '10001',
                        'name' => 'Task',
                        'subtask' => false,
                    ],
                    'project' => [
                        'id' => '10000',
                        'key' => 'TEST',
                        'name' => 'Test Project',
                    ],
                    'summary' => 'Fix authentication bug in login flow',
                    'description' => [
                        'type' => 'doc',
                        'version' => 1,
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => 'Users are unable to log in when using SSO. The authentication flow breaks after the redirect from the identity provider.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'priority' => [
                        'id' => '2',
                        'name' => 'High',
                    ],
                    'labels' => ['bug', 'authentication', 'urgent'],
                    'status' => [
                        'id' => '3',
                        'name' => 'In Progress',
                        'statusCategory' => [
                            'id' => 4,
                            'key' => 'indeterminate',
                            'name' => 'In Progress',
                        ],
                    ],
                    'assignee' => [
                        'accountId' => '456',
                        'emailAddress' => 'developer@example.com',
                        'displayName' => 'Developer',
                    ],
                    'reporter' => [
                        'accountId' => '789',
                        'emailAddress' => 'reporter@example.com',
                        'displayName' => 'Reporter',
                    ],
                    'created' => '2024-01-10T10:00:00.000+0000',
                    'updated' => '2024-01-10T12:00:00.000+0000',
                    'customfield_10016' => 5, // Story points
                    'customfield_10020' => "- User can log in successfully\n- Session is maintained\n- Redirect works properly", // Acceptance criteria
                ],
            ],
            'changelog' => [
                'id' => '10100',
                'items' => [
                    [
                        'field' => 'status',
                        'fieldtype' => 'jira',
                        'from' => '1',
                        'fromString' => 'To Do',
                        'to' => '3',
                        'toString' => 'In Progress',
                    ],
                ],
            ],
        ];
    }
}
