<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiImplementerContract;
use App\DTO\ImplementInputDto;
use App\Models\Patch;
use App\Models\Plan;
use App\Models\Ticket;
use App\Services\Context\AstTools\JsAstTool;
use App\Services\Context\AstTools\PhpAstTool;
use App\Services\WorkspaceRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job to implement a plan.
 * Uses AST tools for code modifications, runs format+lint.
 */
class ImplementPlanJob implements ShouldQueue
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
    public $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Ticket $ticket,
        public Plan $plan,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AiImplementerContract $implementer,
        PhpAstTool $phpAst,
        JsAstTool $jsAst,
        WorkspaceRunner $runner,
    ): void {
        Log::info('Starting plan implementation', [
            'ticket_id' => $this->ticket->id,
            'plan_id' => $this->plan->id,
        ]);

        try {
            $workspacePath = $this->getWorkspacePath();
            $modifiedFiles = [];
            $diffStats = ['additions' => 0, 'deletions' => 0];

            // Process each step in the plan
            foreach ($this->plan->plan_json['steps'] ?? [] as $step) {
                Log::info('Implementing step', [
                    'step_id' => $step['id'],
                    'intent' => $step['intent'],
                ]);

                // Prepare implementation input
                $input = new ImplementInputDto(
                    step: $step,
                    context: $this->buildStepContext($step),
                    workspace: $workspacePath,
                );

                // Get implementation from AI
                $patchSummary = $implementer->implement($input);

                // Apply changes based on intent
                foreach ($step['targets'] ?? [] as $target) {
                    $filePath = $workspacePath.'/'.$target['path'];

                    switch ($step['intent']) {
                        case 'add':
                            $this->addFile($filePath, $patchSummary, $modifiedFiles, $diffStats);
                            break;
                        case 'modify':
                            $this->modifyFile(
                                $filePath,
                                $target,
                                $patchSummary,
                                $phpAst,
                                $jsAst,
                                $modifiedFiles,
                                $diffStats
                            );
                            break;
                        case 'remove':
                            $this->removeFile($filePath, $modifiedFiles, $diffStats);
                            break;
                        case 'add_test':
                            $this->addTestFile($filePath, $patchSummary, $modifiedFiles, $diffStats);
                            break;
                        case 'refactor':
                            $this->refactorCode(
                                $filePath,
                                $patchSummary,
                                $phpAst,
                                $jsAst,
                                $modifiedFiles,
                                $diffStats
                            );
                            break;
                    }
                }
            }

            // Run format and lint on modified files
            $this->formatAndLintFiles($workspacePath, $modifiedFiles, $runner);

            // Generate overall patch summary
            $patchData = [
                'files_touched' => $modifiedFiles,
                'diff_stats' => $diffStats,
                'risk_score' => $this->calculateRiskScore($modifiedFiles, $diffStats),
                'summary' => "Implemented {$this->plan->plan_json['summary']}",
            ];

            // Store patch
            $patch = Patch::create([
                'ticket_id' => $this->ticket->id,
                'files_touched' => $modifiedFiles,
                'diff_stats' => $diffStats,
                'risk_score' => $patchData['risk_score'],
                'summary' => $patchData,
            ]);

            Log::info('Implementation completed', [
                'ticket_id' => $this->ticket->id,
                'patch_id' => $patch->id,
                'files_modified' => count($modifiedFiles),
                'lines_added' => $diffStats['additions'],
                'lines_removed' => $diffStats['deletions'],
            ]);

            // Update workflow state
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'IMPLEMENTING',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'implementation_completed_at' => now()->toIso8601String(),
                        'patch_id' => $patch->id,
                    ]),
                ]);
            }

            // Dispatch next job
            RunChecksJob::dispatch($this->ticket, $patch)->delay(now()->addSeconds(5));

        } catch (\Exception $e) {
            Log::error('Implementation failed', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update workflow with error
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'FAILED',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'implementation_error' => $e->getMessage(),
                        'implementation_failed_at' => now()->toIso8601String(),
                    ]),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Get workspace path for the ticket.
     */
    private function getWorkspacePath(): string
    {
        $workspacePath = Storage::disk('local')->path('workspaces/'.$this->ticket->id.'/repo');

        if (! File::exists($workspacePath)) {
            throw new \Exception('Workspace not found. Run BuildContextJob first.');
        }

        return $workspacePath;
    }

    /**
     * Build context for a specific step.
     */
    private function buildStepContext(array $step): array
    {
        $context = [];

        // Add target file contents
        foreach ($step['targets'] ?? [] as $target) {
            $filePath = $this->getWorkspacePath().'/'.$target['path'];
            if (File::exists($filePath)) {
                $context[] = [
                    'type' => 'file',
                    'path' => $target['path'],
                    'content' => File::get($filePath),
                ];
            }
        }

        // Add dependencies context
        foreach ($step['dependencies'] ?? [] as $depId) {
            foreach ($this->plan->plan_json['steps'] ?? [] as $depStep) {
                if ($depStep['id'] === $depId) {
                    $context[] = [
                        'type' => 'dependency',
                        'step' => $depStep,
                    ];
                    break;
                }
            }
        }

        return $context;
    }

    /**
     * Add a new file.
     */
    private function addFile(string $filePath, $patchSummary, array &$modifiedFiles, array &$diffStats): void
    {
        $directory = dirname($filePath);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Get content from patch summary
        $content = $patchSummary->changes[0]['content'] ?? '';

        File::put($filePath, $content);

        $modifiedFiles[] = str_replace($this->getWorkspacePath().'/', '', $filePath);
        $diffStats['additions'] += substr_count($content, "\n") + 1;

        Log::info('Added file', ['path' => $filePath]);
    }

    /**
     * Modify an existing file.
     */
    private function modifyFile(
        string $filePath,
        array $target,
        $patchSummary,
        PhpAstTool $phpAst,
        JsAstTool $jsAst,
        array &$modifiedFiles,
        array &$diffStats,
    ): void {
        if (! File::exists($filePath)) {
            Log::warning('File not found for modification', ['path' => $filePath]);

            return;
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $originalContent = File::get($filePath);
        $modified = false;

        // Use AST tools for structured modifications
        if ($extension === 'php' && $target['type'] === 'method') {
            try {
                // Modify method using PHP AST
                $className = $target['class'] ?? $this->extractClassName($filePath);
                $methodName = $target['method'] ?? 'unknown';
                $newCode = $patchSummary->changes[0]['content'] ?? '';

                $phpAst->modifyMethod($filePath, $className, $methodName, $newCode);
                $modified = true;
            } catch (\Exception $e) {
                Log::warning('AST modification failed, using diff', ['error' => $e->getMessage()]);
            }
        } elseif (in_array($extension, ['js', 'ts', 'jsx', 'tsx']) && $target['type'] === 'function') {
            try {
                // Modify function using JS AST
                $functionName = $target['function'] ?? 'unknown';
                $newCode = $patchSummary->changes[0]['content'] ?? '';

                $jsAst->modifyFunction($filePath, $functionName, $newCode);
                $modified = true;
            } catch (\Exception $e) {
                Log::warning('AST modification failed, using diff', ['error' => $e->getMessage()]);
            }
        }

        // Fall back to diff-based modification
        if (! $modified) {
            $newContent = $this->applyDiff($originalContent, $patchSummary);
            File::put($filePath, $newContent);
        }

        $modifiedFiles[] = str_replace($this->getWorkspacePath().'/', '', $filePath);

        // Calculate diff stats
        $newContent = File::get($filePath);
        $originalLines = substr_count($originalContent, "\n");
        $newLines = substr_count($newContent, "\n");

        if ($newLines > $originalLines) {
            $diffStats['additions'] += $newLines - $originalLines;
        } else {
            $diffStats['deletions'] += $originalLines - $newLines;
        }

        Log::info('Modified file', ['path' => $filePath]);
    }

    /**
     * Remove a file.
     */
    private function removeFile(string $filePath, array &$modifiedFiles, array &$diffStats): void
    {
        if (File::exists($filePath)) {
            $content = File::get($filePath);
            $diffStats['deletions'] += substr_count($content, "\n") + 1;

            File::delete($filePath);
            $modifiedFiles[] = str_replace($this->getWorkspacePath().'/', '', $filePath);

            Log::info('Removed file', ['path' => $filePath]);
        }
    }

    /**
     * Add a test file.
     */
    private function addTestFile(string $filePath, $patchSummary, array &$modifiedFiles, array &$diffStats): void
    {
        // Ensure test directory exists
        $testDir = dirname($filePath);
        if (! File::exists($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        // Generate test content
        $testContent = $patchSummary->changes[0]['content'] ?? '';

        File::put($filePath, $testContent);

        $modifiedFiles[] = str_replace($this->getWorkspacePath().'/', '', $filePath);
        $diffStats['additions'] += substr_count($testContent, "\n") + 1;

        Log::info('Added test file', ['path' => $filePath]);
    }

    /**
     * Refactor code in a file.
     */
    private function refactorCode(
        string $filePath,
        $patchSummary,
        PhpAstTool $phpAst,
        JsAstTool $jsAst,
        array &$modifiedFiles,
        array &$diffStats,
    ): void {
        // Similar to modify but with more extensive changes
        $this->modifyFile($filePath, ['type' => 'file'], $patchSummary, $phpAst, $jsAst, $modifiedFiles, $diffStats);
    }

    /**
     * Apply diff-based changes.
     */
    private function applyDiff(string $original, $patchSummary): string
    {
        // Simple implementation - in real world, use a proper diff library
        foreach ($patchSummary->changes ?? [] as $change) {
            if (isset($change['old']) && isset($change['new'])) {
                $original = str_replace($change['old'], $change['new'], $original);
            }
        }

        return $original;
    }

    /**
     * Extract class name from PHP file.
     */
    private function extractClassName(string $filePath): string
    {
        $content = File::get($filePath);
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Unknown';
    }

    /**
     * Format and lint modified files.
     */
    private function formatAndLintFiles(string $workspacePath, array $modifiedFiles, WorkspaceRunner $runner): void
    {
        $profile = $this->ticket->project->language_profile ?? [];

        // Run formatter if available
        if (isset($profile['commands']['format'])) {
            foreach ($modifiedFiles as $file) {
                $filePath = $workspacePath.'/'.$file;
                if (File::exists($filePath)) {
                    $command = str_replace('**', $file, $profile['commands']['format']);

                    $result = $runner->runDirect(
                        workspacePath: $workspacePath,
                        command: $command,
                        timeout: 30,
                    );

                    if ($result->exitCode !== 0) {
                        Log::warning('Format failed for file', [
                            'file' => $file,
                            'error' => $result->stderr,
                        ]);
                    }
                }
            }
        }

        // Run linter if available
        if (isset($profile['commands']['lint'])) {
            $lintCommand = $profile['commands']['lint'];
            $result = $runner->runDirect(
                workspacePath: $workspacePath,
                command: $lintCommand,
                timeout: 60,
            );

            if ($result->exitCode !== 0) {
                Log::warning('Lint check failed', ['error' => $result->stderr]);
            }
        }
    }

    /**
     * Calculate risk score based on changes.
     */
    private function calculateRiskScore(array $modifiedFiles, array $diffStats): int
    {
        $score = 0;

        // Risk based on number of files
        $score += min(count($modifiedFiles) * 2, 20);

        // Risk based on lines changed
        $totalLines = $diffStats['additions'] + $diffStats['deletions'];
        $score += min($totalLines / 10, 30);

        // Risk for critical files
        foreach ($modifiedFiles as $file) {
            if (str_contains($file, 'auth') || str_contains($file, 'security')) {
                $score += 10;
            }
            if (str_contains($file, 'database/migrations')) {
                $score += 15;
            }
            if (str_contains($file, 'config/')) {
                $score += 5;
            }
        }

        return min($score, 100);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ImplementPlanJob failed', [
            'ticket_id' => $this->ticket->id,
            'plan_id' => $this->plan->id,
            'error' => $exception->getMessage(),
        ]);

        // Update workflow state
        if ($this->ticket->workflow) {
            $this->ticket->workflow->update([
                'state' => 'FAILED',
                'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                    'implementation_job_failed' => true,
                    'implementation_job_error' => $exception->getMessage(),
                ]),
            ]);
        }
    }
}
