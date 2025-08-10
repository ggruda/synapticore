<?php

declare(strict_types=1);

namespace App\Services\SelfHealing;

use App\Models\Ticket;
use App\Models\Workflow;
use App\Services\Context\EmbeddingIndexer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service to collect failure information and create repair bundles.
 * Captures exceptions, diffs, context, and logs for self-healing.
 */
class FailureCollector
{
    /**
     * Create a new failure collector instance.
     */
    public function __construct(
        private readonly EmbeddingIndexer $embeddingIndexer,
    ) {}

    /**
     * Capture failure and create repair bundle.
     *
     * @return string Path to the bundle in storage
     */
    public function captureFailure(
        \Throwable $exception,
        Ticket $ticket,
        string $jobName,
        array $additionalContext = [],
    ): string {
        Log::info('Capturing failure for self-healing', [
            'ticket_id' => $ticket->id,
            'job' => $jobName,
            'exception' => get_class($exception),
        ]);

        try {
            // Collect all failure information
            $bundle = $this->buildFailureBundle(
                $exception,
                $ticket,
                $jobName,
                $additionalContext
            );

            // Store bundle to Spaces/MinIO
            $bundlePath = $this->storeBundle($ticket, $bundle);

            // Update workflow with failure info
            if ($ticket->workflow) {
                $this->updateWorkflowWithFailure($ticket->workflow, $bundlePath, $exception);
            }

            Log::info('Failure bundle created', [
                'ticket_id' => $ticket->id,
                'bundle_path' => $bundlePath,
                'bundle_size' => strlen(json_encode($bundle)),
            ]);

            return $bundlePath;

        } catch (\Exception $e) {
            Log::error('Failed to capture failure bundle', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build complete failure bundle.
     */
    private function buildFailureBundle(
        \Throwable $exception,
        Ticket $ticket,
        string $jobName,
        array $additionalContext,
    ): array {
        $bundle = [
            'version' => '1.0',
            'timestamp' => now()->toIso8601String(),
            'ticket' => [
                'id' => $ticket->id,
                'external_key' => $ticket->external_key,
                'title' => $ticket->title,
                'body' => $ticket->body,
                'acceptance_criteria' => $ticket->acceptance_criteria,
            ],
            'failure' => [
                'job' => $jobName,
                'exception' => [
                    'class' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $this->sanitizeTrace($exception->getTraceAsString()),
                ],
            ],
            'context' => array_merge(
                $this->collectSystemContext($ticket),
                $additionalContext
            ),
            'artifacts' => $this->collectArtifacts($ticket),
            'suggestions' => $this->generateSuggestions($exception, $ticket, $jobName),
        ];

        // Add last diffs if available
        if ($lastDiffs = $this->collectLastDiffs($ticket)) {
            $bundle['last_diffs'] = $lastDiffs;
        }

        // Add relevant code context from embeddings
        if ($relevantContext = $this->collectRelevantContext($exception, $ticket)) {
            $bundle['code_context'] = $relevantContext;
        }

        // Add repository profile
        if ($ticket->project->language_profile) {
            $bundle['repo_profile'] = $ticket->project->language_profile;
        }

        // Add command logs
        if ($commandLogs = $this->collectCommandLogs($ticket)) {
            $bundle['command_logs'] = $commandLogs;
        }

        return $bundle;
    }

    /**
     * Collect system context.
     */
    private function collectSystemContext(Ticket $ticket): array
    {
        $context = [
            'workflow_state' => $ticket->workflow?->state,
            'workflow_meta' => $ticket->workflow?->meta ?? [],
            'project_id' => $ticket->project_id,
            'project_name' => $ticket->project->name,
        ];

        // Add plan information
        if ($ticket->plan) {
            $context['plan'] = [
                'risk' => $ticket->plan->risk,
                'test_strategy' => $ticket->plan->test_strategy,
                'steps_count' => count($ticket->plan->plan_json['steps'] ?? []),
            ];
        }

        // Add patch information
        if ($ticket->patches()->exists()) {
            $lastPatch = $ticket->patches()->latest()->first();
            $context['last_patch'] = [
                'id' => $lastPatch->id,
                'files_touched' => count($lastPatch->files_touched ?? []),
                'risk_score' => $lastPatch->risk_score,
                'lines_added' => $lastPatch->diff_stats['additions'] ?? 0,
                'lines_removed' => $lastPatch->diff_stats['deletions'] ?? 0,
            ];
        }

        // Add test results
        if ($ticket->runs()->exists()) {
            $context['test_results'] = [];
            foreach ($ticket->runs()->latest()->limit(5)->get() as $run) {
                $context['test_results'][] = [
                    'type' => $run->type,
                    'status' => $run->status,
                    'created_at' => $run->created_at->toIso8601String(),
                ];
            }
        }

        return $context;
    }

    /**
     * Collect last diffs from patches.
     */
    private function collectLastDiffs(Ticket $ticket): ?array
    {
        $patches = $ticket->patches()->latest()->limit(3)->get();

        if ($patches->isEmpty()) {
            return null;
        }

        $diffs = [];
        foreach ($patches as $patch) {
            $diffs[] = [
                'patch_id' => $patch->id,
                'created_at' => $patch->created_at->toIso8601String(),
                'files_touched' => $patch->files_touched ?? [],
                'summary' => $patch->summary['summary'] ?? '',
            ];
        }

        return $diffs;
    }

    /**
     * Collect relevant code context using embeddings.
     */
    private function collectRelevantContext(\Throwable $exception, Ticket $ticket): array
    {
        $context = [];

        // Build search query from exception
        $query = $exception->getMessage();

        // Add file context if available
        if ($exception->getFile()) {
            $query .= ' '.basename($exception->getFile());
        }

        // Add method/class context from trace
        $trace = $exception->getTrace();
        if (! empty($trace[0])) {
            if (isset($trace[0]['class'])) {
                $query .= ' '.$trace[0]['class'];
            }
            if (isset($trace[0]['function'])) {
                $query .= ' '.$trace[0]['function'];
            }
        }

        try {
            // Search for relevant code
            $hits = $this->embeddingIndexer->search(
                query: $query,
                k: 10,
                projectId: $ticket->project_id
            );

            foreach ($hits as $hit) {
                $context[] = [
                    'content' => $hit->content,
                    'relevance' => $hit->score,
                    'metadata' => $hit->metadata,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to collect embedding context', [
                'error' => $e->getMessage(),
            ]);
        }

        return $context;
    }

    /**
     * Collect command logs from runs.
     */
    private function collectCommandLogs(Ticket $ticket): array
    {
        $logs = [];
        $runs = $ticket->runs()->latest()->limit(5)->get();

        foreach ($runs as $run) {
            if ($run->logs_path) {
                try {
                    $disk = Storage::disk(config('filesystems.default') === 'spaces' ? 'spaces' : 'local');

                    if ($disk->exists($run->logs_path)) {
                        $content = $disk->get($run->logs_path);

                        // Truncate if too large
                        if (strlen($content) > 50000) {
                            $content = substr($content, -50000);
                        }

                        $logs[] = [
                            'type' => $run->type,
                            'status' => $run->status,
                            'created_at' => $run->created_at->toIso8601String(),
                            'content' => $content,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to read command log', [
                        'run_id' => $run->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $logs;
    }

    /**
     * Collect artifacts paths.
     */
    private function collectArtifacts(Ticket $ticket): array
    {
        $artifacts = [];

        // Collect run artifacts
        foreach ($ticket->runs as $run) {
            if ($run->junit_path) {
                $artifacts[] = [
                    'type' => 'junit',
                    'path' => $run->junit_path,
                    'run_type' => $run->type,
                ];
            }
            if ($run->coverage_path) {
                $artifacts[] = [
                    'type' => 'coverage',
                    'path' => $run->coverage_path,
                    'run_type' => $run->type,
                ];
            }
            if ($run->logs_path) {
                $artifacts[] = [
                    'type' => 'logs',
                    'path' => $run->logs_path,
                    'run_type' => $run->type,
                ];
            }
        }

        return $artifacts;
    }

    /**
     * Generate suggestions based on failure.
     */
    private function generateSuggestions(\Throwable $exception, Ticket $ticket, string $jobName): array
    {
        $suggestions = [];

        // Analyze exception type
        $exceptionClass = get_class($exception);
        $message = strtolower($exception->getMessage());

        // Lint failures
        if (str_contains($message, 'lint') || str_contains($message, 'pint') || str_contains($message, 'eslint')) {
            $suggestions[] = [
                'type' => 'lint_fix',
                'priority' => 'high',
                'action' => 'Run code formatter and linter with auto-fix',
                'commands' => $this->getLintFixCommands($ticket),
            ];
        }

        // Test failures
        if (str_contains($message, 'test') || str_contains($message, 'phpunit') || str_contains($message, 'jest')) {
            $suggestions[] = [
                'type' => 'test_fix',
                'priority' => 'high',
                'action' => 'Review test expectations and update implementation',
                'analysis' => 'Check for assertion failures, missing mocks, or incorrect test data',
            ];
        }

        // Type errors
        if (str_contains($message, 'type') || str_contains($message, 'argument') || str_contains($message, 'parameter')) {
            $suggestions[] = [
                'type' => 'type_fix',
                'priority' => 'high',
                'action' => 'Fix type declarations and parameter types',
                'analysis' => 'Ensure correct types are used in method signatures and calls',
            ];
        }

        // Import/namespace issues
        if (str_contains($message, 'class') && str_contains($message, 'not found')) {
            $suggestions[] = [
                'type' => 'import_fix',
                'priority' => 'high',
                'action' => 'Add missing use statements or fix namespace',
                'analysis' => 'Check for missing imports or incorrect namespace declarations',
            ];
        }

        // Syntax errors
        if (str_contains($message, 'syntax') || str_contains($message, 'parse')) {
            $suggestions[] = [
                'type' => 'syntax_fix',
                'priority' => 'critical',
                'action' => 'Fix syntax errors in the code',
                'analysis' => 'Check for missing semicolons, brackets, or quotes',
            ];
        }

        // Permission errors
        if (str_contains($message, 'permission') || str_contains($message, 'denied')) {
            $suggestions[] = [
                'type' => 'permission_fix',
                'priority' => 'medium',
                'action' => 'Check file permissions and ownership',
                'commands' => ['chmod -R 755 storage', 'chown -R www-data:www-data storage'],
            ];
        }

        // Timeout issues
        if (str_contains($jobName, 'timeout') || str_contains($message, 'timeout')) {
            $suggestions[] = [
                'type' => 'timeout_fix',
                'priority' => 'medium',
                'action' => 'Optimize performance or increase timeout',
                'analysis' => 'Consider breaking down large operations or increasing job timeout',
            ];
        }

        // Add general suggestions if no specific ones
        if (empty($suggestions)) {
            $suggestions[] = [
                'type' => 'general',
                'priority' => 'medium',
                'action' => 'Review error message and stack trace',
                'analysis' => 'Manual investigation may be required',
            ];
        }

        return $suggestions;
    }

    /**
     * Get lint fix commands based on project.
     */
    private function getLintFixCommands(Ticket $ticket): array
    {
        $commands = [];
        $profile = $ticket->project->language_profile ?? [];

        // PHP
        if (isset($profile['languages']) && in_array('php', $profile['languages'])) {
            $commands[] = 'vendor/bin/pint';
            $commands[] = 'vendor/bin/php-cs-fixer fix';
        }

        // JavaScript/TypeScript
        if (isset($profile['languages']) &&
            (in_array('javascript', $profile['languages']) || in_array('typescript', $profile['languages']))) {
            $commands[] = 'npm run lint:fix';
            $commands[] = 'npx eslint --fix .';
            $commands[] = 'npx prettier --write .';
        }

        // Python
        if (isset($profile['languages']) && in_array('python', $profile['languages'])) {
            $commands[] = 'black .';
            $commands[] = 'autopep8 --in-place --recursive .';
            $commands[] = 'isort .';
        }

        // Go
        if (isset($profile['languages']) && in_array('go', $profile['languages'])) {
            $commands[] = 'gofmt -w .';
            $commands[] = 'goimports -w .';
        }

        return $commands;
    }

    /**
     * Store bundle to storage.
     */
    private function storeBundle(Ticket $ticket, array $bundle): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $bundleName = "failure_bundle_{$ticket->id}_{$timestamp}.json";
        $path = "failure_bundles/tickets/{$ticket->id}/{$bundleName}";

        // Use spaces disk if available, otherwise local
        $disk = Storage::disk(config('filesystems.default') === 'spaces' ? 'spaces' : 'local');

        // Store main bundle
        $disk->put($path, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Store additional files if needed
        $this->storeAdditionalFiles($ticket, $bundle, dirname($path), $disk);

        Log::info('Failure bundle stored', [
            'ticket_id' => $ticket->id,
            'path' => $path,
            'size' => strlen(json_encode($bundle)),
        ]);

        return $path;
    }

    /**
     * Store additional files with the bundle.
     */
    private function storeAdditionalFiles(Ticket $ticket, array $bundle, string $bundleDir, $disk): void
    {
        // Store workspace files if they exist
        $workspacePath = storage_path('app/workspaces/'.$ticket->id.'/repo');

        if (File::exists($workspacePath)) {
            // Store modified files
            $patch = $ticket->patches()->latest()->first();
            if ($patch && isset($patch->files_touched)) {
                foreach ($patch->files_touched as $file) {
                    $filePath = $workspacePath.'/'.$file;
                    if (File::exists($filePath)) {
                        $content = File::get($filePath);
                        $disk->put(
                            $bundleDir.'/workspace_files/'.str_replace('/', '_', $file),
                            $content
                        );
                    }
                }
            }
        }
    }

    /**
     * Update workflow with failure information.
     */
    private function updateWorkflowWithFailure(Workflow $workflow, string $bundlePath, \Throwable $exception): void
    {
        $failures = $workflow->meta['failures'] ?? [];

        $failures[] = [
            'timestamp' => now()->toIso8601String(),
            'bundle_path' => $bundlePath,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ];

        // Keep only last 10 failures
        if (count($failures) > 10) {
            $failures = array_slice($failures, -10);
        }

        $workflow->update([
            'meta' => array_merge($workflow->meta ?? [], [
                'failures' => $failures,
                'last_failure_at' => now()->toIso8601String(),
                'failure_count' => count($failures),
            ]),
        ]);
    }

    /**
     * Sanitize stack trace to remove sensitive information.
     */
    private function sanitizeTrace(string $trace): string
    {
        // Remove absolute paths
        $trace = str_replace(base_path(), '[APP]', $trace);

        // Remove vendor paths
        $trace = preg_replace('/\/vendor\/[^:]+/', '/vendor/[...]', $trace);

        // Remove potential credentials in URLs
        $trace = preg_replace('/([a-z]+:\/\/)([^:]+):([^@]+)@/', '$1[REDACTED]:[REDACTED]@', $trace);

        return $trace;
    }

    /**
     * Load failure bundle from storage.
     */
    public function loadBundle(string $bundlePath): ?array
    {
        try {
            $disk = Storage::disk(config('filesystems.default') === 'spaces' ? 'spaces' : 'local');

            if (! $disk->exists($bundlePath)) {
                return null;
            }

            $content = $disk->get($bundlePath);

            return json_decode($content, true);

        } catch (\Exception $e) {
            Log::error('Failed to load bundle', [
                'path' => $bundlePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get latest failure bundle for a ticket.
     */
    public function getLatestBundle(Ticket $ticket): ?array
    {
        $workflow = $ticket->workflow;

        if (! $workflow || empty($workflow->meta['failures'])) {
            return null;
        }

        $failures = $workflow->meta['failures'];
        $latestFailure = end($failures);

        if (! isset($latestFailure['bundle_path'])) {
            return null;
        }

        return $this->loadBundle($latestFailure['bundle_path']);
    }
}
