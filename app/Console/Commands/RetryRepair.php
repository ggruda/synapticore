<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RepairAttemptJob;
use App\Models\Ticket;
use App\Services\SelfHealing\FailureCollector;
use Illuminate\Console\Command;

/**
 * Command to retry repair for a failed ticket.
 */
class RetryRepair extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synaptic:retry 
                            {ticket : Ticket ID or external key}
                            {--bundle= : Specific bundle path to use}
                            {--force : Force retry even if max attempts reached}
                            {--reset : Reset repair attempts counter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enqueue repair attempt for a failed ticket';

    /**
     * Execute the console command.
     */
    public function handle(FailureCollector $failureCollector): int
    {
        $ticketIdentifier = $this->argument('ticket');

        // Find ticket by ID or external key
        $ticket = is_numeric($ticketIdentifier)
            ? Ticket::find($ticketIdentifier)
            : Ticket::where('external_key', $ticketIdentifier)->first();

        if (! $ticket) {
            $this->error("Ticket not found: {$ticketIdentifier}");

            return Command::FAILURE;
        }

        $this->info("📋 Ticket: {$ticket->external_key} - {$ticket->title}");
        $this->newLine();

        // Check workflow state
        if (! $ticket->workflow) {
            $this->error('No workflow found for this ticket');

            return Command::FAILURE;
        }

        $this->displayWorkflowInfo($ticket);

        // Get bundle path
        $bundlePath = $this->option('bundle');

        if (! $bundlePath) {
            // Get latest bundle
            $bundle = $failureCollector->getLatestBundle($ticket);

            if (! $bundle) {
                $this->error('No failure bundles found for this ticket');
                $this->info('Cannot initiate repair without a failure bundle');

                return Command::FAILURE;
            }

            // Get bundle path from workflow metadata
            $failures = $ticket->workflow->meta['failures'] ?? [];
            if (! empty($failures)) {
                $latestFailure = end($failures);
                $bundlePath = $latestFailure['bundle_path'] ?? null;
            }

            if (! $bundlePath) {
                $this->error('Bundle path not found in workflow metadata');

                return Command::FAILURE;
            }
        }

        $this->info("📦 Using bundle: {$bundlePath}");

        // Check repair attempts
        $repairAttempts = $ticket->workflow->meta['repair_attempts'] ?? 0;
        $maxAttempts = 2;

        if ($repairAttempts >= $maxAttempts && ! $this->option('force')) {
            $this->warn("⚠️ Maximum repair attempts ({$maxAttempts}) already reached");

            if (! $this->confirm('Do you want to force another attempt?')) {
                return Command::FAILURE;
            }
        }

        // Reset attempts if requested
        if ($this->option('reset')) {
            $ticket->workflow->update([
                'meta' => array_merge($ticket->workflow->meta ?? [], [
                    'repair_attempts' => 0,
                    'repair_reset_at' => now()->toIso8601String(),
                ]),
            ]);

            $this->info('✅ Repair attempts counter reset');
            $repairAttempts = 0;
        }

        // Display repair strategy
        $this->displayRepairStrategy($bundlePath, $failureCollector);

        // Confirm repair
        if (! $this->confirm('Proceed with repair attempt?')) {
            return Command::FAILURE;
        }

        // Dispatch repair job
        try {
            RepairAttemptJob::dispatch(
                $ticket,
                $bundlePath,
                $repairAttempts + 1
            )->delay(now()->addSeconds(5));

            $this->info('✅ Repair job queued successfully');
            $this->info('Job will execute in 5 seconds');
            $this->newLine();

            $this->warn('Run queue worker to process the repair:');
            $this->line('php artisan queue:work --stop-when-empty');

            // Update workflow metadata
            $ticket->workflow->update([
                'meta' => array_merge($ticket->workflow->meta ?? [], [
                    'repair_initiated_at' => now()->toIso8601String(),
                    'repair_initiated_by' => 'console',
                ]),
            ]);

        } catch (\Exception $e) {
            $this->error('Failed to dispatch repair job: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Display workflow information.
     */
    private function displayWorkflowInfo(Ticket $ticket): void
    {
        $workflow = $ticket->workflow;

        $this->info('🔄 Workflow Status');
        $this->table(
            ['Field', 'Value'],
            [
                ['State', $workflow->state],
                ['Retries', $workflow->retries],
                ['Repair Attempts', $workflow->meta['repair_attempts'] ?? 0],
                ['Last Failure', $workflow->meta['last_failure_at'] ?? 'N/A'],
                ['Repair Escalated', $workflow->meta['repair_escalated'] ?? false ? 'Yes' : 'No'],
            ]
        );

        // Show recent failures
        $failures = $workflow->meta['failures'] ?? [];
        if (! empty($failures)) {
            $this->newLine();
            $this->info('Recent Failures ('.count($failures).'):');

            foreach (array_slice($failures, -3) as $failure) {
                $this->line('  - '.$failure['timestamp'].': '.$failure['exception']);
                $this->line('    '.substr($failure['message'], 0, 80).'...');
            }
        }

        $this->newLine();
    }

    /**
     * Display repair strategy based on bundle.
     */
    private function displayRepairStrategy(string $bundlePath, FailureCollector $failureCollector): void
    {
        $bundle = $failureCollector->loadBundle($bundlePath);

        if (! $bundle) {
            $this->warn('Could not load bundle to display strategy');

            return;
        }

        $this->info('🛠️ Repair Strategy');

        // Show failure type
        $exception = $bundle['failure']['exception'] ?? [];
        $this->line('Exception: '.($exception['class'] ?? 'unknown'));
        $this->line('Message: '.substr($exception['message'] ?? '', 0, 100).'...');
        $this->newLine();

        // Show suggestions
        if (! empty($bundle['suggestions'])) {
            $this->info('Suggested Repairs:');

            foreach ($bundle['suggestions'] as $index => $suggestion) {
                $this->line(($index + 1).". [{$suggestion['priority']}] {$suggestion['type']}");
                $this->line("   Action: {$suggestion['action']}");

                if (isset($suggestion['commands'])) {
                    $this->line('   Commands:');
                    foreach ($suggestion['commands'] as $command) {
                        $this->line("     - {$command}");
                    }
                }
            }
        }

        $this->newLine();
        $this->info('The repair job will:');
        $this->line('1. Analyze the failure and determine repair strategy');
        $this->line('2. Apply minimal corrective patches (max 50 lines)');
        $this->line('3. Run checks to verify the fix');
        $this->line('4. Resume workflow if successful or escalate if failed');
        $this->newLine();
    }
}
