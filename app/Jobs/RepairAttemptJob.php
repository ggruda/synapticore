<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiImplementerContract;
use App\DTO\ImplementInputDto;
use App\Models\Ticket;
use App\Services\Context\AstTools\JsAstTool;
use App\Services\Context\AstTools\PhpAstTool;
use App\Services\SelfHealing\FailureCollector;
use App\Services\WorkspaceRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Job to attempt automatic repair based on failure bundles.
 * Uses AI to generate minimal corrective patches.
 */
class RepairAttemptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * Maximum repair attempts.
     */
    private const MAX_ATTEMPTS = 2;

    /**
     * Maximum lines to change in a repair.
     */
    private const MAX_DIFF_BUDGET = 50;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Ticket $ticket,
        public string $bundlePath,
        public int $attemptNumber = 1,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        FailureCollector $failureCollector,
        AiImplementerContract $implementer,
        PhpAstTool $phpAst,
        JsAstTool $jsAst,
        WorkspaceRunner $runner,
    ): void {
        Log::info('Starting repair attempt', [
            'ticket_id' => $this->ticket->id,
            'bundle_path' => $this->bundlePath,
            'attempt' => $this->attemptNumber,
        ]);

        // Check if we've exceeded max attempts
        if ($this->attemptNumber > self::MAX_ATTEMPTS) {
            $this->escalateFailure('Maximum repair attempts exceeded');

            return;
        }

        try {
            // Load failure bundle
            $bundle = $failureCollector->loadBundle($this->bundlePath);

            if (! $bundle) {
                throw new \Exception('Failed to load failure bundle');
            }

            // Analyze failure and determine repair strategy
            $repairStrategy = $this->analyzeFailure($bundle);

            // Apply repair based on strategy
            $repairResult = $this->applyRepair(
                $repairStrategy,
                $bundle,
                $implementer,
                $phpAst,
                $jsAst,
                $runner
            );

            if ($repairResult['success']) {
                $this->handleSuccess($repairResult);
            } else {
                $this->handleRepairFailure($repairResult, $bundle);
            }

        } catch (\Exception $e) {
            Log::error('Repair attempt failed', [
                'ticket_id' => $this->ticket->id,
                'attempt' => $this->attemptNumber,
                'error' => $e->getMessage(),
            ]);

            // Capture new failure and potentially retry
            $newBundlePath = $failureCollector->captureFailure(
                $e,
                $this->ticket,
                'RepairAttemptJob',
                ['previous_bundle' => $this->bundlePath]
            );

            if ($this->attemptNumber < self::MAX_ATTEMPTS) {
                // Dispatch another repair attempt
                self::dispatch($this->ticket, $newBundlePath, $this->attemptNumber + 1)
                    ->delay(now()->addSeconds(30));
            } else {
                $this->escalateFailure($e->getMessage());
            }
        }
    }

    /**
     * Analyze failure and determine repair strategy.
     */
    private function analyzeFailure(array $bundle): array
    {
        $strategy = [
            'type' => 'unknown',
            'priority' => 'medium',
            'actions' => [],
            'target_files' => [],
        ];

        // Get suggestions from bundle
        $suggestions = $bundle['suggestions'] ?? [];

        if (empty($suggestions)) {
            return $this->getDefaultStrategy($bundle);
        }

        // Find highest priority suggestion
        $highestPriority = null;
        foreach ($suggestions as $suggestion) {
            if (! $highestPriority || $this->getPriorityWeight($suggestion['priority']) > $this->getPriorityWeight($highestPriority['priority'])) {
                $highestPriority = $suggestion;
            }
        }

        if (! $highestPriority) {
            return $this->getDefaultStrategy($bundle);
        }

        // Build strategy from suggestion
        $strategy['type'] = $highestPriority['type'];
        $strategy['priority'] = $highestPriority['priority'];

        // Determine actions based on type
        switch ($highestPriority['type']) {
            case 'lint_fix':
                $strategy['actions'] = ['format', 'lint'];
                $strategy['target_files'] = $this->extractFilesFromLogs($bundle);
                break;

            case 'test_fix':
                $strategy['actions'] = ['fix_tests', 'update_assertions'];
                $strategy['target_files'] = $this->extractTestFiles($bundle);
                break;

            case 'type_fix':
                $strategy['actions'] = ['fix_types', 'update_signatures'];
                $strategy['target_files'] = $this->extractFilesFromException($bundle);
                break;

            case 'import_fix':
                $strategy['actions'] = ['add_imports', 'fix_namespace'];
                $strategy['target_files'] = $this->extractFilesFromException($bundle);
                break;

            case 'syntax_fix':
                $strategy['actions'] = ['fix_syntax'];
                $strategy['target_files'] = $this->extractFilesFromException($bundle);
                break;

            default:
                $strategy['actions'] = ['analyze', 'minimal_fix'];
                $strategy['target_files'] = $this->extractAllRelevantFiles($bundle);
        }

        // Add commands if available
        if (isset($highestPriority['commands'])) {
            $strategy['commands'] = $highestPriority['commands'];
        }

        return $strategy;
    }

    /**
     * Apply repair based on strategy.
     */
    private function applyRepair(
        array $strategy,
        array $bundle,
        AiImplementerContract $implementer,
        PhpAstTool $phpAst,
        JsAstTool $jsAst,
        WorkspaceRunner $runner,
    ): array {
        $workspacePath = storage_path('app/workspaces/'.$this->ticket->id.'/repo');
        $result = [
            'success' => false,
            'changes' => [],
            'test_results' => [],
        ];

        // Handle lint/format fixes
        if (in_array('format', $strategy['actions']) || in_array('lint', $strategy['actions'])) {
            $result = $this->applyLintFixes($workspacePath, $strategy, $runner);

            if ($result['success']) {
                return $result;
            }
        }

        // Handle type/import fixes using AST
        if (in_array('fix_types', $strategy['actions']) || in_array('add_imports', $strategy['actions'])) {
            $result = $this->applyAstFixes(
                $workspacePath,
                $strategy,
                $bundle,
                $phpAst,
                $jsAst
            );

            if ($result['success']) {
                return $result;
            }
        }

        // Handle test fixes
        if (in_array('fix_tests', $strategy['actions'])) {
            $result = $this->applyTestFixes(
                $workspacePath,
                $strategy,
                $bundle,
                $implementer
            );

            if ($result['success']) {
                return $result;
            }
        }

        // Fall back to AI-powered minimal fix
        if (! $result['success']) {
            $result = $this->applyAiFix(
                $workspacePath,
                $strategy,
                $bundle,
                $implementer
            );
        }

        // Run verification checks
        if ($result['success']) {
            $result = $this->verifyFix($workspacePath, $result, $runner);
        }

        return $result;
    }

    /**
     * Apply lint and format fixes.
     */
    private function applyLintFixes(string $workspacePath, array $strategy, WorkspaceRunner $runner): array
    {
        Log::info('Applying lint fixes', [
            'ticket_id' => $this->ticket->id,
            'files' => $strategy['target_files'],
        ]);

        $result = [
            'success' => false,
            'changes' => [],
            'test_results' => [],
        ];

        // Run format/lint commands
        $commands = $strategy['commands'] ?? $this->getDefaultLintCommands();

        foreach ($commands as $command) {
            $runResult = $runner->runDirect(
                workspacePath: $workspacePath,
                command: $command,
                timeout: 60,
            );

            if ($runResult->exitCode === 0) {
                $result['success'] = true;
                $result['changes'][] = "Applied: $command";

                Log::info('Lint command succeeded', [
                    'command' => $command,
                    'ticket_id' => $this->ticket->id,
                ]);
            }
        }

        // Check if any files were modified
        if ($result['success']) {
            $modifiedFiles = $this->getModifiedFiles($workspacePath);
            $result['changes'] = array_merge($result['changes'], $modifiedFiles);
        }

        return $result;
    }

    /**
     * Apply AST-based fixes.
     */
    private function applyAstFixes(
        string $workspacePath,
        array $strategy,
        array $bundle,
        PhpAstTool $phpAst,
        JsAstTool $jsAst,
    ): array {
        Log::info('Applying AST fixes', [
            'ticket_id' => $this->ticket->id,
            'type' => $strategy['type'],
        ]);

        $result = [
            'success' => false,
            'changes' => [],
            'test_results' => [],
        ];

        $exception = $bundle['failure']['exception'] ?? [];

        foreach ($strategy['target_files'] as $file) {
            $filePath = $workspacePath.'/'.$file;

            if (! File::exists($filePath)) {
                continue;
            }

            $extension = pathinfo($filePath, PATHINFO_EXTENSION);

            try {
                if ($extension === 'php') {
                    // Fix PHP issues
                    if (str_contains($strategy['type'], 'import')) {
                        $this->fixPhpImports($filePath, $exception, $phpAst);
                        $result['success'] = true;
                        $result['changes'][] = "Fixed imports in $file";
                    } elseif (str_contains($strategy['type'], 'type')) {
                        $this->fixPhpTypes($filePath, $exception, $phpAst);
                        $result['success'] = true;
                        $result['changes'][] = "Fixed types in $file";
                    }
                } elseif (in_array($extension, ['js', 'ts', 'jsx', 'tsx'])) {
                    // Fix JavaScript/TypeScript issues
                    if (str_contains($strategy['type'], 'import')) {
                        $this->fixJsImports($filePath, $exception, $jsAst);
                        $result['success'] = true;
                        $result['changes'][] = "Fixed imports in $file";
                    }
                }
            } catch (\Exception $e) {
                Log::warning('AST fix failed for file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Apply test fixes.
     */
    private function applyTestFixes(
        string $workspacePath,
        array $strategy,
        array $bundle,
        AiImplementerContract $implementer,
    ): array {
        Log::info('Applying test fixes', [
            'ticket_id' => $this->ticket->id,
        ]);

        $result = [
            'success' => false,
            'changes' => [],
            'test_results' => [],
        ];

        // Extract test failure information
        $testLogs = $this->extractTestLogs($bundle);

        if (empty($testLogs)) {
            return $result;
        }

        // Prepare fix input for AI
        $input = new ImplementInputDto(
            step: [
                'intent' => 'fix_tests',
                'targets' => array_map(fn ($f) => ['path' => $f, 'type' => 'test'], $strategy['target_files']),
                'rationale' => 'Fix failing tests based on error logs',
                'acceptance' => ['Tests pass', 'No regression'],
            ],
            context: [
                'test_logs' => $testLogs,
                'failure' => $bundle['failure']['exception'] ?? [],
            ],
            workspace: $workspacePath,
        );

        try {
            // Get fix from AI
            $patchSummary = $implementer->implement($input);

            // Apply fixes
            foreach ($patchSummary->changes ?? [] as $change) {
                if (isset($change['file']) && isset($change['content'])) {
                    $filePath = $workspacePath.'/'.$change['file'];
                    File::put($filePath, $change['content']);
                    $result['changes'][] = "Fixed test: {$change['file']}";
                }
            }

            $result['success'] = ! empty($result['changes']);

        } catch (\Exception $e) {
            Log::error('Test fix failed', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Apply AI-powered minimal fix.
     */
    private function applyAiFix(
        string $workspacePath,
        array $strategy,
        array $bundle,
        AiImplementerContract $implementer,
    ): array {
        Log::info('Applying AI fix', [
            'ticket_id' => $this->ticket->id,
            'strategy_type' => $strategy['type'],
        ]);

        $result = [
            'success' => false,
            'changes' => [],
            'test_results' => [],
        ];

        // Build comprehensive context
        $context = [
            'failure' => $bundle['failure'],
            'suggestions' => $bundle['suggestions'] ?? [],
            'code_context' => $bundle['code_context'] ?? [],
            'last_diffs' => $bundle['last_diffs'] ?? [],
            'command_logs' => array_slice($bundle['command_logs'] ?? [], -2),
            'diff_budget' => self::MAX_DIFF_BUDGET,
            'policies' => [
                'minimal_changes' => true,
                'preserve_functionality' => true,
                'respect_style' => true,
            ],
        ];

        // Prepare input for AI
        $input = new ImplementInputDto(
            step: [
                'intent' => 'minimal_fix',
                'targets' => array_map(fn ($f) => ['path' => $f, 'type' => 'file'], $strategy['target_files']),
                'rationale' => "Fix: {$bundle['failure']['exception']['message']}",
                'acceptance' => ['Error resolved', 'Tests pass', 'Minimal changes'],
            ],
            context: $context,
            workspace: $workspacePath,
        );

        try {
            // Get minimal fix from AI
            $patchSummary = $implementer->implement($input);

            // Apply changes within diff budget
            $totalLines = 0;
            foreach ($patchSummary->changes ?? [] as $change) {
                $lines = substr_count($change['content'] ?? '', "\n");

                if ($totalLines + $lines > self::MAX_DIFF_BUDGET) {
                    Log::warning('Diff budget exceeded, skipping change', [
                        'file' => $change['file'] ?? 'unknown',
                        'lines' => $lines,
                    ]);

                    continue;
                }

                if (isset($change['file']) && isset($change['content'])) {
                    $filePath = $workspacePath.'/'.$change['file'];

                    // Apply patch
                    if (isset($change['old']) && isset($change['new'])) {
                        $content = File::get($filePath);
                        $content = str_replace($change['old'], $change['new'], $content);
                        File::put($filePath, $content);
                    } else {
                        File::put($filePath, $change['content']);
                    }

                    $result['changes'][] = "Fixed: {$change['file']}";
                    $totalLines += $lines;
                }
            }

            $result['success'] = ! empty($result['changes']);

        } catch (\Exception $e) {
            Log::error('AI fix failed', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Verify fix by running checks.
     */
    private function verifyFix(string $workspacePath, array $result, WorkspaceRunner $runner): array
    {
        Log::info('Verifying fix', [
            'ticket_id' => $this->ticket->id,
            'changes' => count($result['changes']),
        ]);

        $profile = $this->ticket->project->language_profile ?? [];

        // Run lint check
        if (isset($profile['commands']['lint'])) {
            $lintResult = $runner->runDirect(
                workspacePath: $workspacePath,
                command: $profile['commands']['lint'],
                timeout: 60,
            );

            $result['test_results']['lint'] = $lintResult->exitCode === 0 ? 'passed' : 'failed';
        }

        // Run tests
        if (isset($profile['commands']['test'])) {
            $testResult = $runner->runDirect(
                workspacePath: $workspacePath,
                command: $profile['commands']['test'],
                timeout: 180,
            );

            $result['test_results']['test'] = $testResult->exitCode === 0 ? 'passed' : 'failed';
        }

        // Check if all passed
        $allPassed = true;
        foreach ($result['test_results'] as $check => $status) {
            if ($status !== 'passed') {
                $allPassed = false;
                break;
            }
        }

        $result['success'] = $allPassed;

        return $result;
    }

    /**
     * Handle successful repair.
     */
    private function handleSuccess(array $repairResult): void
    {
        Log::info('Repair successful', [
            'ticket_id' => $this->ticket->id,
            'attempt' => $this->attemptNumber,
            'changes' => $repairResult['changes'],
        ]);

        // Update workflow
        if ($this->ticket->workflow) {
            $this->ticket->workflow->update([
                'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                    'repair_success' => true,
                    'repair_attempts' => $this->attemptNumber,
                    'repair_changes' => $repairResult['changes'],
                    'repaired_at' => now()->toIso8601String(),
                ]),
            ]);

            // Resume workflow from last state
            $this->resumeWorkflow();
        }
    }

    /**
     * Handle repair failure.
     */
    private function handleRepairFailure(array $repairResult, array $bundle): void
    {
        Log::warning('Repair failed', [
            'ticket_id' => $this->ticket->id,
            'attempt' => $this->attemptNumber,
            'result' => $repairResult,
        ]);

        if ($this->attemptNumber < self::MAX_ATTEMPTS) {
            // Try another attempt with different strategy
            self::dispatch($this->ticket, $this->bundlePath, $this->attemptNumber + 1)
                ->delay(now()->addSeconds(30));
        } else {
            $this->escalateFailure('Repair attempts failed', $repairResult);
        }
    }

    /**
     * Escalate failure with summary.
     */
    private function escalateFailure(string $reason, array $details = []): void
    {
        Log::error('Escalating failure', [
            'ticket_id' => $this->ticket->id,
            'reason' => $reason,
            'attempts' => $this->attemptNumber,
            'details' => $details,
        ]);

        // Update workflow with escalation
        if ($this->ticket->workflow) {
            $this->ticket->workflow->update([
                'state' => 'FAILED',
                'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                    'repair_escalated' => true,
                    'escalation_reason' => $reason,
                    'repair_attempts' => $this->attemptNumber,
                    'escalation_details' => $details,
                    'escalated_at' => now()->toIso8601String(),
                    'action_required' => 'Manual intervention needed',
                ]),
            ]);
        }

        // Could send notification here
    }

    /**
     * Resume workflow after successful repair.
     */
    private function resumeWorkflow(): void
    {
        $workflow = $this->ticket->workflow;

        if (! $workflow) {
            return;
        }

        // Determine next job based on previous state
        $previousState = $workflow->meta['previous_state'] ?? null;

        switch ($previousState) {
            case 'TESTING':
                // Re-run checks
                RunChecksJob::dispatch($this->ticket, $this->ticket->patches()->latest()->first())
                    ->delay(now()->addSeconds(10));
                break;

            case 'IMPLEMENTING':
                // Continue with implementation
                ImplementPlanJob::dispatch($this->ticket, $this->ticket->plan)
                    ->delay(now()->addSeconds(10));
                break;

            default:
                // Try to continue from current state
                app(\App\Services\WorkflowOrchestrator::class)->dispatchNextJob($workflow);
        }
    }

    // Helper methods...

    private function getPriorityWeight(string $priority): int
    {
        return match ($priority) {
            'critical' => 100,
            'high' => 75,
            'medium' => 50,
            'low' => 25,
            default => 0,
        };
    }

    private function getDefaultStrategy(array $bundle): array
    {
        return [
            'type' => 'general',
            'priority' => 'medium',
            'actions' => ['analyze', 'minimal_fix'],
            'target_files' => $this->extractAllRelevantFiles($bundle),
        ];
    }

    private function extractFilesFromLogs(array $bundle): array
    {
        $files = [];

        foreach ($bundle['command_logs'] ?? [] as $log) {
            if (preg_match_all('/([a-zA-Z0-9_\/\-\.]+\.(php|js|ts|py|go))/', $log['content'] ?? '', $matches)) {
                $files = array_merge($files, $matches[1]);
            }
        }

        return array_unique($files);
    }

    private function extractTestFiles(array $bundle): array
    {
        $files = [];

        foreach ($bundle['command_logs'] ?? [] as $log) {
            if ($log['type'] === 'test') {
                if (preg_match_all('/([a-zA-Z0-9_\/\-\.]*[Tt]est[a-zA-Z0-9_\/\-\.]*\.(php|js|ts))/', $log['content'] ?? '', $matches)) {
                    $files = array_merge($files, $matches[1]);
                }
            }
        }

        return array_unique($files);
    }

    private function extractFilesFromException(array $bundle): array
    {
        $files = [];
        $exception = $bundle['failure']['exception'] ?? [];

        if (isset($exception['file'])) {
            $files[] = str_replace('[APP]/', '', $exception['file']);
        }

        if (isset($exception['trace'])) {
            if (preg_match_all('/\[APP\]\/([^:]+)/', $exception['trace'], $matches)) {
                $files = array_merge($files, $matches[1]);
            }
        }

        return array_unique($files);
    }

    private function extractAllRelevantFiles(array $bundle): array
    {
        return array_unique(array_merge(
            $this->extractFilesFromLogs($bundle),
            $this->extractFilesFromException($bundle),
            $bundle['last_diffs'][0]['files_touched'] ?? []
        ));
    }

    private function getDefaultLintCommands(): array
    {
        return [
            'vendor/bin/pint',
            'npm run lint:fix',
            'black .',
            'gofmt -w .',
        ];
    }

    private function getModifiedFiles(string $workspacePath): array
    {
        $result = shell_exec("cd $workspacePath && git diff --name-only 2>/dev/null");

        if ($result) {
            return array_filter(explode("\n", trim($result)));
        }

        return [];
    }

    private function extractTestLogs(array $bundle): array
    {
        $testLogs = [];

        foreach ($bundle['command_logs'] ?? [] as $log) {
            if ($log['type'] === 'test' && $log['status'] === 'failed') {
                $testLogs[] = $log['content'] ?? '';
            }
        }

        return $testLogs;
    }

    private function fixPhpImports(string $filePath, array $exception, PhpAstTool $phpAst): void
    {
        // Extract missing class from exception message
        if (preg_match('/Class [\'"]?([^\'"]+)[\'"]? not found/', $exception['message'] ?? '', $matches)) {
            $missingClass = $matches[1];

            // Try to add import
            $phpAst->addImport($filePath, $missingClass);
        }
    }

    private function fixPhpTypes(string $filePath, array $exception, PhpAstTool $phpAst): void
    {
        // This would need more sophisticated type fixing logic
        // For now, just format the file
        shell_exec("vendor/bin/pint $filePath");
    }

    private function fixJsImports(string $filePath, array $exception, JsAstTool $jsAst): void
    {
        // Extract missing module from exception
        if (preg_match('/Cannot find module [\'"]([^\'"]+)[\'"]/', $exception['message'] ?? '', $matches)) {
            $missingModule = $matches[1];

            // Try to add import
            $jsAst->addImport($filePath, $missingModule, $missingModule);
        }
    }
}
