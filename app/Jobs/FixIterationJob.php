<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiImplementerContract;
use App\DTO\ImplementInputDto;
use App\Models\Patch;
use App\Models\Ticket;
use App\Services\WorkspaceRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to fix issues found during review.
 * Limited iterations to prevent infinite loops.
 */
class FixIterationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Ticket $ticket,
        public Patch $patch,
        public array $issues,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AiImplementerContract $implementer,
        WorkspaceRunner $runner,
    ): void {
        Log::info('Starting fix iteration', [
            'ticket_id' => $this->ticket->id,
            'patch_id' => $this->patch->id,
            'issues_count' => count($this->issues),
        ]);

        try {
            $workspacePath = storage_path('app/workspaces/'.$this->ticket->id.'/repo');
            $fixedFiles = [];

            // Group issues by file
            $issuesByFile = $this->groupIssuesByFile($this->issues);

            foreach ($issuesByFile as $file => $fileIssues) {
                // Prepare fix input
                $input = new ImplementInputDto(
                    step: [
                        'intent' => 'fix',
                        'targets' => [['path' => $file, 'type' => 'file']],
                        'rationale' => 'Fix review issues: '.implode('; ', array_column($fileIssues, 'message')),
                        'acceptance' => ['Issues resolved', 'Tests pass'],
                    ],
                    context: array_merge(
                        $this->buildFixContext($file, $fileIssues),
                        ['issues' => $fileIssues]
                    ),
                    workspace: $workspacePath,
                );

                // Get fix from AI
                $fixSummary = $implementer->implement($input);

                // Apply fixes
                $this->applyFixes($workspacePath.'/'.$file, $fixSummary);
                $fixedFiles[] = $file;
            }

            // Run formatter on fixed files
            $this->formatFiles($workspacePath, $fixedFiles, $runner);

            // Update patch with fix information
            $this->patch->update([
                'summary' => array_merge($this->patch->summary ?? [], [
                    'fixes_applied' => true,
                    'fixed_files' => $fixedFiles,
                    'fixed_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::info('Fix iteration completed', [
                'ticket_id' => $this->ticket->id,
                'fixed_files' => count($fixedFiles),
            ]);

            // Re-run checks and review
            RunChecksJob::dispatch($this->ticket, $this->patch)
                ->delay(now()->addSeconds(5));

        } catch (\Exception $e) {
            Log::error('Fix iteration failed', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
            ]);

            // Update workflow
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'FAILED',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'fix_error' => $e->getMessage(),
                        'fix_failed_at' => now()->toIso8601String(),
                    ]),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Group issues by file.
     */
    private function groupIssuesByFile(array $issues): array
    {
        $grouped = [];

        foreach ($issues as $issue) {
            $file = $issue['file'] ?? 'unknown';
            if (! isset($grouped[$file])) {
                $grouped[$file] = [];
            }
            $grouped[$file][] = $issue;
        }

        return $grouped;
    }

    /**
     * Build context for fixing issues.
     */
    private function buildFixContext(string $file, array $issues): array
    {
        $context = [];

        // Add file content
        $filePath = storage_path('app/workspaces/'.$this->ticket->id.'/repo/'.$file);
        if (file_exists($filePath)) {
            $context[] = [
                'type' => 'file',
                'path' => $file,
                'content' => file_get_contents($filePath),
            ];
        }

        // Add issue details
        foreach ($issues as $issue) {
            $context[] = [
                'type' => 'issue',
                'severity' => $issue['severity'] ?? 'medium',
                'message' => $issue['message'] ?? '',
                'line' => $issue['line'] ?? null,
            ];
        }

        return $context;
    }

    /**
     * Apply fixes to a file.
     */
    private function applyFixes(string $filePath, $fixSummary): void
    {
        if (! file_exists($filePath)) {
            Log::warning('File not found for fix', ['path' => $filePath]);

            return;
        }

        $content = file_get_contents($filePath);

        // Apply changes from fix summary
        foreach ($fixSummary->changes ?? [] as $change) {
            if (isset($change['old']) && isset($change['new'])) {
                $content = str_replace($change['old'], $change['new'], $content);
            }
        }

        file_put_contents($filePath, $content);
    }

    /**
     * Format fixed files.
     */
    private function formatFiles(string $workspacePath, array $files, WorkspaceRunner $runner): void
    {
        $profile = $this->ticket->project->language_profile ?? [];

        if (isset($profile['commands']['format'])) {
            foreach ($files as $file) {
                $command = str_replace('**', $file, $profile['commands']['format']);
                $runner->runDirect(
                    workspacePath: $workspacePath,
                    command: $command,
                    timeout: 30,
                );
            }
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FixIterationJob failed', [
            'ticket_id' => $this->ticket->id,
            'patch_id' => $this->patch->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
