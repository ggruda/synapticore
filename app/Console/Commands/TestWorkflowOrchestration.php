<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workflow;
use App\Services\WorkflowOrchestrator;
use Illuminate\Console\Command;

/**
 * Test command for end-to-end workflow orchestration.
 */
class TestWorkflowOrchestration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflow:test 
                            {--ticket= : Existing ticket ID to use}
                            {--create : Create a new test ticket}
                            {--status : Show workflow status}
                            {--reset : Reset failed workflow}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test workflow orchestration from ticket to PR';

    /**
     * Execute the console command.
     */
    public function handle(WorkflowOrchestrator $orchestrator): int
    {
        $this->info('🚀 Testing Workflow Orchestration');
        $this->newLine();

        // Show statistics first
        if ($this->option('status')) {
            $this->showStatistics($orchestrator);

            return Command::SUCCESS;
        }

        // Get or create ticket
        $ticket = $this->getOrCreateTicket();

        if (! $ticket) {
            $this->error('No ticket available for testing');

            return Command::FAILURE;
        }

        // Reset workflow if requested
        if ($this->option('reset') && $ticket->workflow) {
            $this->resetWorkflow($ticket->workflow, $orchestrator);
        }

        // Display current status
        $this->displayTicketInfo($ticket);

        // Check if workflow exists
        if ($ticket->workflow) {
            $this->displayWorkflowStatus($ticket->workflow, $orchestrator);

            if ($ticket->workflow->state === Workflow::STATE_DONE) {
                $this->info('✅ Workflow already completed!');
                $this->displayResults($ticket);

                return Command::SUCCESS;
            }

            if ($ticket->workflow->state === Workflow::STATE_FAILED) {
                $this->warn('⚠️ Workflow is in failed state');

                if ($this->confirm('Do you want to retry the workflow?')) {
                    try {
                        $orchestrator->retryWorkflow($ticket->workflow);
                        $this->info('✅ Workflow retry initiated');
                    } catch (\Exception $e) {
                        $this->error('Failed to retry: '.$e->getMessage());

                        return Command::FAILURE;
                    }
                } else {
                    return Command::FAILURE;
                }
            } else {
                $this->info('ℹ️ Workflow already in progress');
                $this->warn('Run queue worker to continue: php artisan queue:work');

                return Command::SUCCESS;
            }
        } else {
            // Start new workflow
            if ($this->confirm('Start workflow orchestration for this ticket?')) {
                $this->startWorkflow($ticket, $orchestrator);
            } else {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Get or create a test ticket.
     */
    private function getOrCreateTicket(): ?Ticket
    {
        if ($ticketId = $this->option('ticket')) {
            $ticket = Ticket::find($ticketId);
            if (! $ticket) {
                $this->error("Ticket not found: {$ticketId}");

                return null;
            }

            return $ticket;
        }

        if ($this->option('create')) {
            return $this->createTestTicket();
        }

        // Find recent ticket without workflow or with failed workflow
        $ticket = Ticket::whereDoesntHave('workflow')
            ->orWhereHas('workflow', function ($query) {
                $query->where('state', Workflow::STATE_FAILED);
            })
            ->latest()
            ->first();

        if (! $ticket) {
            $this->warn('No suitable ticket found');
            if ($this->confirm('Create a new test ticket?')) {
                return $this->createTestTicket();
            }
        }

        return $ticket;
    }

    /**
     * Create a test ticket.
     */
    private function createTestTicket(): Ticket
    {
        // Get or create project
        $project = Project::firstOrCreate(
            ['name' => 'Workflow Test Project'],
            [
                'repo_urls' => ['https://github.com/laravel/laravel'],
                'default_branch' => '11.x',
                'allowed_paths' => ['app/', 'tests/'],
                'language_profile' => [],
            ]
        );

        $ticket = Ticket::create([
            'project_id' => $project->id,
            'external_key' => 'TEST-'.rand(1000, 9999),
            'source' => 'jira',
            'title' => 'Add user profile update feature',
            'body' => 'As a user, I want to be able to update my profile information including name, email, and bio.',
            'acceptance_criteria' => [
                'User can update their name',
                'User can update their email with validation',
                'User can update their bio (max 500 characters)',
                'Changes are saved to database',
                'Success message is displayed after update',
            ],
            'labels' => ['feature', 'user-profile', 'backend'],
            'status' => 'in_progress',
            'priority' => 'medium',
            'meta' => [
                'test_ticket' => true,
                'created_via' => 'workflow:test',
            ],
        ]);

        $this->info("✅ Created test ticket: {$ticket->external_key}");

        return $ticket;
    }

    /**
     * Display ticket information.
     */
    private function displayTicketInfo(Ticket $ticket): void
    {
        $this->info('📋 Ticket Information');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $ticket->id],
                ['External Key', $ticket->external_key],
                ['Title', $ticket->title],
                ['Status', $ticket->status],
                ['Priority', $ticket->priority],
                ['Project', $ticket->project->name],
            ]
        );
        $this->newLine();
    }

    /**
     * Display workflow status.
     */
    private function displayWorkflowStatus(Workflow $workflow, WorkflowOrchestrator $orchestrator): void
    {
        $status = $orchestrator->getStatus($workflow);

        $this->info('📊 Workflow Status');
        $this->table(
            ['Field', 'Value'],
            [
                ['State', $status['current_state']],
                ['Is Complete', $status['is_complete'] ? 'Yes' : 'No'],
                ['Is Failed', $status['is_failed'] ? 'Yes' : 'No'],
                ['Retries', $status['retries']],
                ['Has Plan', $status['has_plan'] ? 'Yes' : 'No'],
                ['Has Patch', $status['has_patch'] ? 'Yes' : 'No'],
                ['Has PR', $status['has_pr'] ? 'Yes' : 'No'],
                ['Duration', $status['duration_minutes'].' minutes'],
            ]
        );

        if (! empty($status['next_possible_states'])) {
            $this->info('Next possible states: '.implode(', ', $status['next_possible_states']));
        }

        $this->newLine();
    }

    /**
     * Start workflow for ticket.
     */
    private function startWorkflow(Ticket $ticket, WorkflowOrchestrator $orchestrator): void
    {
        $this->info('🚀 Starting workflow orchestration...');

        try {
            $workflow = $orchestrator->startWorkflow($ticket);

            $this->info('✅ Workflow started successfully!');
            $this->info("Workflow ID: {$workflow->id}");
            $this->info("Current State: {$workflow->state}");
            $this->newLine();

            $this->warn('⚠️ Jobs have been queued. Run the queue worker to process them:');
            $this->line('php artisan queue:work --stop-when-empty');
            $this->newLine();

            $this->info('Or run continuously with:');
            $this->line('php artisan queue:work');

        } catch (\Exception $e) {
            $this->error('Failed to start workflow: '.$e->getMessage());
        }
    }

    /**
     * Reset failed workflow.
     */
    private function resetWorkflow(Workflow $workflow, WorkflowOrchestrator $orchestrator): void
    {
        $this->warn('Resetting workflow...');

        try {
            $workflow->update([
                'state' => Workflow::STATE_INGESTED,
                'retries' => 0,
                'meta' => array_merge($workflow->meta ?? [], [
                    'reset_at' => now()->toIso8601String(),
                ]),
            ]);

            $this->info('✅ Workflow reset to INGESTED state');
        } catch (\Exception $e) {
            $this->error('Failed to reset workflow: '.$e->getMessage());
        }
    }

    /**
     * Display workflow results.
     */
    private function displayResults(Ticket $ticket): void
    {
        $this->info('📈 Workflow Results');

        // Plan
        if ($ticket->plan) {
            $this->info('✅ Plan generated');
            $this->line('  Risk: '.$ticket->plan->risk);
            $this->line('  Steps: '.count($ticket->plan->plan_json['steps'] ?? []));
        }

        // Patches
        $patches = $ticket->patches;
        if ($patches->isNotEmpty()) {
            $this->info('✅ '.count($patches).' patch(es) created');
            foreach ($patches as $patch) {
                $this->line('  Files touched: '.count($patch->files_touched ?? []));
                $this->line('  Risk score: '.$patch->risk_score);
            }
        }

        // Runs
        $runs = $ticket->runs;
        if ($runs->isNotEmpty()) {
            $this->info('✅ '.count($runs).' check(s) executed');
            foreach ($runs->groupBy('type') as $type => $typeRuns) {
                $passed = $typeRuns->where('status', 'passed')->count();
                $total = $typeRuns->count();
                $this->line("  {$type}: {$passed}/{$total} passed");
            }
        }

        // Pull Requests
        $prs = $ticket->pullRequests;
        if ($prs->isNotEmpty()) {
            $this->info('✅ '.count($prs).' pull request(s) created');
            foreach ($prs as $pr) {
                $this->line('  URL: '.$pr->url);
                $this->line('  Branch: '.$pr->branch_name);
                $this->line('  Draft: '.($pr->is_draft ? 'Yes' : 'No'));
            }
        }

        $this->newLine();
    }

    /**
     * Show workflow statistics.
     */
    private function showStatistics(WorkflowOrchestrator $orchestrator): void
    {
        $stats = $orchestrator->getStatistics();

        $this->info('📊 Workflow Statistics');
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

        if (! empty($stats['by_state'])) {
            $this->newLine();
            $this->info('Workflows by State:');
            foreach ($stats['by_state'] as $state => $count) {
                $this->line("  {$state}: {$count}");
            }
        }
    }
}
