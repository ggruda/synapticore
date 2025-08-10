<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\Worklog;
use App\Services\Time\TrackedSection;
use Illuminate\Console\Command;

/**
 * Test command for worklog tracking functionality.
 */
class TestWorklogTracking extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'worklog:test
                            {--test-tracking : Test basic time tracking}
                            {--test-sync : Test Jira worklog sync}
                            {--test-batch : Test batch sync}
                            {--ticket-id= : Ticket ID to use for testing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test worklog tracking and Jira sync functionality';

    /**
     * Execute the console command.
     */
    public function handle(TrackedSection $tracker): int
    {
        $this->info('🕐 Testing Worklog Tracking System');
        $this->newLine();

        if ($this->option('test-tracking')) {
            $this->testBasicTracking($tracker);
        }

        if ($this->option('test-sync')) {
            $this->testJiraSync($tracker);
        }

        if ($this->option('test-batch')) {
            $this->testBatchSync($tracker);
        }

        if (! $this->option('test-tracking') && ! $this->option('test-sync') && ! $this->option('test-batch')) {
            $this->testBasicTracking($tracker);
            $this->newLine();
            $this->testJiraSync($tracker);
        }

        return Command::SUCCESS;
    }

    /**
     * Test basic time tracking functionality.
     */
    private function testBasicTracking(TrackedSection $tracker): void
    {
        $this->info('Testing Basic Time Tracking');
        $this->newLine();

        // Get or create test ticket
        $ticket = $this->getOrCreateTestTicket();

        // Test synchronous tracking
        $this->info('Running synchronous tracked section (2 seconds)...');

        $result = $tracker->run($ticket, 'test', function () {
            // Simulate some work
            sleep(2);

            return 'Work completed';
        }, 'Testing synchronous time tracking');

        $this->info("Result: {$result}");

        // Test async tracking
        $this->info('Starting async tracked section...');
        $worklog = $tracker->startAsync($ticket, 'implement', 'Testing async tracking');

        // Simulate some work
        sleep(1);

        $tracker->completeAsync($worklog);
        $this->info('Async tracking completed');

        // Show total time
        $totals = $tracker->getTotalTime($ticket);
        $this->newLine();
        $this->info('Total Time Tracked:');
        $this->table(
            ['Phase', 'Duration'],
            collect($totals['by_phase_formatted'])->map(fn ($time, $phase) => [
                ucfirst($phase),
                $time,
            ])->toArray()
        );
        $this->info("Total: {$totals['total_formatted']}");

        // Show worklogs
        $this->newLine();
        $this->info('Worklogs Created:');
        $worklogs = Worklog::where('ticket_id', $ticket->id)->get();
        $this->table(
            ['ID', 'Phase', 'Seconds', 'Status', 'Notes'],
            $worklogs->map(fn ($log) => [
                $log->id,
                $log->phase,
                $log->seconds,
                $log->status,
                substr($log->notes ?? 'N/A', 0, 50),
            ])->toArray()
        );
    }

    /**
     * Test Jira worklog sync.
     */
    private function testJiraSync(TrackedSection $tracker): void
    {
        $this->info('Testing Jira Worklog Sync');
        $this->newLine();

        // Check if immediate mode is enabled
        $pushMode = config('synaptic.worklog.push_mode');
        $this->info("Current push mode: {$pushMode}");

        if ($pushMode !== 'immediate') {
            $this->warn('Immediate sync is disabled. Set SYNAPTIC_WORKLOG_PUSH=immediate to test');

            return;
        }

        // Get or create test ticket
        $ticket = $this->getOrCreateTestTicket();

        // Create a worklog with immediate sync
        $this->info('Creating worklog with immediate sync...');

        try {
            $tracker->run($ticket, 'plan', function () {
                // Simulate planning work
                sleep(3);

                return 'Planning completed';
            }, 'Automated test of worklog sync to Jira');

            $this->info('✅ Worklog created and sync attempted');

            // Check sync status
            $lastWorklog = Worklog::where('ticket_id', $ticket->id)
                ->orderBy('id', 'desc')
                ->first();

            if ($lastWorklog) {
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Worklog ID', $lastWorklog->id],
                        ['Seconds', $lastWorklog->seconds],
                        ['Synced At', $lastWorklog->synced_at ?? 'Not synced'],
                        ['Sync Status', $lastWorklog->sync_status ?? 'Unknown'],
                        ['Sync Error', $lastWorklog->sync_error ?? 'None'],
                    ]
                );

                if ($lastWorklog->sync_status === 'success') {
                    $this->info('✅ Successfully synced to Jira!');
                    $this->info("Check Jira ticket: {$ticket->external_key}");
                } elseif ($lastWorklog->sync_status === 'failed') {
                    $this->error('❌ Sync failed: '.$lastWorklog->sync_error);
                } else {
                    $this->warn('⚠️ Sync status unknown');
                }
            }
        } catch (\Exception $e) {
            $this->error('Error during sync test: '.$e->getMessage());
        }
    }

    /**
     * Test batch sync functionality.
     */
    private function testBatchSync(TrackedSection $tracker): void
    {
        $this->info('Testing Batch Sync');
        $this->newLine();

        // Count unsynced worklogs
        $unsyncedCount = Worklog::whereNull('synced_at')
            ->where('status', 'completed')
            ->count();

        $this->info("Found {$unsyncedCount} unsynced worklogs");

        if ($unsyncedCount === 0) {
            $this->warn('No unsynced worklogs to process');

            return;
        }

        // Run batch sync
        $this->info('Running batch sync...');
        $synced = $tracker->batchSync(limit: 10);

        $this->info("✅ Synced {$synced} worklogs");

        // Show results
        $remaining = Worklog::whereNull('synced_at')
            ->where('status', 'completed')
            ->count();

        $this->info("Remaining unsynced: {$remaining}");
    }

    /**
     * Get or create a test ticket.
     */
    private function getOrCreateTestTicket(): Ticket
    {
        $ticketId = $this->option('ticket-id');

        if ($ticketId) {
            $ticket = Ticket::find($ticketId);
            if ($ticket) {
                $this->info("Using existing ticket: {$ticket->external_key}");

                return $ticket;
            }
        }

        // Create test project if needed
        $project = Project::firstOrCreate(
            ['name' => 'Worklog Test Project'],
            [
                'repo_urls' => ['https://github.com/example/test'],
                'default_branch' => 'main',
                'allowed_paths' => ['src/', 'app/'],
                'language_profile' => ['languages' => ['php']],
            ]
        );

        // Create test ticket
        $ticket = Ticket::create([
            'project_id' => $project->id,
            'external_key' => 'TEST-WL-'.rand(1000, 9999),
            'source' => 'jira',
            'title' => 'Test ticket for worklog tracking',
            'body' => 'This ticket is used to test worklog time tracking',
            'acceptance_criteria' => ['Time is tracked correctly'],
            'labels' => ['test', 'worklog'],
            'status' => 'in_progress',
            'priority' => 'low',
            'meta' => ['test' => true],
        ]);

        $this->info("Created test ticket: {$ticket->external_key}");

        return $ticket;
    }
}
