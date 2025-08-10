<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Patch;
use App\Models\Run;
use App\Models\Ticket;
use App\Services\SelfHealing\FailureCollector;
use App\Services\Time\TrackedSection;
use App\Services\WorkspaceRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job to run checks (lint, typecheck, test) on implementation.
 * Stores artifacts (JUnit, coverage, logs) to Spaces.
 */
class RunChecksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Ticket $ticket,
        public Patch $patch,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        WorkspaceRunner $runner,
        TrackedSection $tracker,
    ): void {
        Log::info('Starting checks for patch', [
            'ticket_id' => $this->ticket->id,
            'patch_id' => $this->patch->id,
        ]);

        try {
            // Track time for the testing phase
            $tracker->run($this->ticket, 'test', function () use ($runner) {
                $workspacePath = Storage::disk('local')->path('workspaces/'.$this->ticket->id.'/repo');
                $profile = $this->ticket->project->language_profile ?? [];
                $results = [];

                // Run lint check
                if (isset($profile['commands']['lint'])) {
                    $results['lint'] = $this->runCheck(
                        'lint',
                        $profile['commands']['lint'],
                        $workspacePath,
                        $runner
                    );
                }

                // Run typecheck
                if (isset($profile['commands']['typecheck'])) {
                    $results['typecheck'] = $this->runCheck(
                        'typecheck',
                        $profile['commands']['typecheck'],
                        $workspacePath,
                        $runner
                    );
                }

                // Run tests
                if (isset($profile['commands']['test'])) {
                    $results['test'] = $this->runCheck(
                        'test',
                        $profile['commands']['test'],
                        $workspacePath,
                        $runner,
                        generateCoverage: true
                    );
                }

                // Check if all mandatory checks passed
                $allPassed = true;
                $mandatoryChecks = config('synaptic.policies.mandatory_checks', []);
                $failedChecks = [];

                foreach ($mandatoryChecks as $check => $required) {
                    if ($required && (! isset($results[$check]) || $results[$check]['status'] !== 'passed')) {
                        $allPassed = false;
                        $failedChecks[] = $check;
                        Log::warning('Mandatory check failed', [
                            'check' => $check,
                            'status' => $results[$check]['status'] ?? 'not_run',
                        ]);
                    }
                }

                // If checks failed, attempt self-healing
                if (! $allPassed && ! empty($failedChecks)) {
                    $this->attemptSelfHealing($failedChecks, $results);
                }

                // Update workflow state
                if ($this->ticket->workflow) {
                    $this->ticket->workflow->update([
                        'state' => 'TESTING',
                        'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                            'checks_completed_at' => now()->toIso8601String(),
                            'checks_passed' => $allPassed,
                            'check_results' => $results,
                        ]),
                    ]);
                }

                Log::info('Checks completed', [
                    'ticket_id' => $this->ticket->id,
                    'all_passed' => $allPassed,
                    'results' => array_map(fn ($r) => $r['status'] ?? 'unknown', $results),
                ]);

                // Dispatch next job
                ReviewPatchJob::dispatch($this->ticket, $this->patch, $allPassed)
                    ->delay(now()->addSeconds(5));
            }, 'Running tests and checks on implementation');
        } catch (\Exception $e) {
            Log::error('Checks failed', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update workflow with error
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'FAILED',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'checks_error' => $e->getMessage(),
                        'checks_failed_at' => now()->toIso8601String(),
                    ]),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Run a specific check.
     */
    private function runCheck(
        string $type,
        string $command,
        string $workspacePath,
        WorkspaceRunner $runner,
        bool $generateCoverage = false,
    ): array {
        Log::info("Running {$type} check", [
            'ticket_id' => $this->ticket->id,
            'command' => $command,
        ]);

        // Add coverage flags if needed
        if ($generateCoverage && $type === 'test') {
            $command = $this->addCoverageFlags($command);
        }

        // Run the check
        $result = $runner->runDirect(
            workspacePath: $workspacePath,
            command: $command,
            timeout: 300,
        );

        // Store logs to Spaces/MinIO
        $logsPath = $this->storeArtifact(
            "{$type}_output.log",
            $result->stdout."\n\nERRORs:\n".$result->stderr
        );

        // Parse and store JUnit XML if available (for tests)
        $junitPath = null;
        if ($type === 'test') {
            $junitPath = $this->findAndStoreJunitXml($workspacePath);
        }

        // Parse and store coverage report if available
        $coveragePath = null;
        if ($generateCoverage && $type === 'test') {
            $coveragePath = $this->findAndStoreCoverageReport($workspacePath);
        }

        // Create Run record
        $run = Run::create([
            'ticket_id' => $this->ticket->id,
            'type' => $type,
            'status' => $result->exitCode === 0 ? 'passed' : 'failed',
            'junit_path' => $junitPath,
            'coverage_path' => $coveragePath,
            'logs_path' => $logsPath,
        ]);

        Log::info("{$type} check completed", [
            'ticket_id' => $this->ticket->id,
            'run_id' => $run->id,
            'status' => $run->status,
            'exit_code' => $result->exitCode,
        ]);

        return [
            'status' => $run->status,
            'run_id' => $run->id,
            'logs_path' => $logsPath,
            'junit_path' => $junitPath,
            'coverage_path' => $coveragePath,
            'exit_code' => $result->exitCode,
        ];
    }

    /**
     * Add coverage flags to test command.
     */
    private function addCoverageFlags(string $command): string
    {
        // Detect test framework and add appropriate coverage flags
        if (str_contains($command, 'phpunit')) {
            return $command.' --coverage-html coverage --coverage-xml coverage/xml';
        } elseif (str_contains($command, 'jest')) {
            return $command.' --coverage --coverageReporters=html --coverageReporters=lcov';
        } elseif (str_contains($command, 'pytest')) {
            return $command.' --cov --cov-report=html --cov-report=xml';
        } elseif (str_contains($command, 'go test')) {
            return $command.' -coverprofile=coverage.out';
        }

        return $command;
    }

    /**
     * Find and store JUnit XML results.
     */
    private function findAndStoreJunitXml(string $workspacePath): ?string
    {
        $possiblePaths = [
            'junit.xml',
            'test-results/junit.xml',
            'coverage/junit.xml',
            'tests/_output/junit.xml',
            'test-results.xml',
        ];

        foreach ($possiblePaths as $path) {
            $fullPath = $workspacePath.'/'.$path;
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);

                return $this->storeArtifact('junit.xml', $content);
            }
        }

        // Try to find any XML file with "junit" in name
        $files = glob($workspacePath.'/**/*junit*.xml');
        if (! empty($files)) {
            $content = file_get_contents($files[0]);

            return $this->storeArtifact('junit.xml', $content);
        }

        return null;
    }

    /**
     * Find and store coverage report.
     */
    private function findAndStoreCoverageReport(string $workspacePath): ?string
    {
        $possiblePaths = [
            'coverage/index.html',
            'coverage/lcov-report/index.html',
            'htmlcov/index.html',
            'coverage.html',
        ];

        foreach ($possiblePaths as $path) {
            $fullPath = $workspacePath.'/'.$path;
            if (file_exists($fullPath)) {
                // For HTML coverage, store the entire directory as a zip
                $coverageDir = dirname($fullPath);
                $zipPath = $this->createCoverageZip($coverageDir);
                $content = file_get_contents($zipPath);
                @unlink($zipPath);

                return $this->storeArtifact('coverage.zip', $content);
            }
        }

        // Try XML coverage formats
        $xmlPaths = [
            'coverage.xml',
            'coverage/coverage.xml',
            'coverage/clover.xml',
        ];

        foreach ($xmlPaths as $path) {
            $fullPath = $workspacePath.'/'.$path;
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);

                return $this->storeArtifact('coverage.xml', $content);
            }
        }

        return null;
    }

    /**
     * Create a zip archive of coverage directory.
     */
    private function createCoverageZip(string $coverageDir): string
    {
        $zipPath = sys_get_temp_dir().'/coverage_'.uniqid().'.zip';
        $zip = new \ZipArchive;

        if ($zip->open($zipPath, \ZipArchive::CREATE) === true) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($coverageDir),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (! $file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($coverageDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();
        }

        return $zipPath;
    }

    /**
     * Store artifact to Spaces/MinIO.
     */
    private function storeArtifact(string $filename, string $content): string
    {
        $path = "artifacts/tickets/{$this->ticket->id}/".date('Y-m-d')."/{$filename}";

        // Use spaces disk if available, otherwise local
        $disk = Storage::disk(config('filesystems.default') === 'spaces' ? 'spaces' : 'local');

        $disk->put($path, $content);

        Log::info('Stored artifact', [
            'ticket_id' => $this->ticket->id,
            'filename' => $filename,
            'path' => $path,
            'size' => strlen($content),
        ]);

        return $path;
    }

    /**
     * Attempt self-healing for failed checks.
     */
    private function attemptSelfHealing(array $failedChecks, array $results): void
    {
        // Only attempt self-healing for certain types of failures
        $healableChecks = ['lint', 'typecheck'];
        $shouldHeal = false;

        foreach ($failedChecks as $check) {
            if (in_array($check, $healableChecks)) {
                $shouldHeal = true;
                break;
            }
        }

        if ($shouldHeal) {
            Log::info('Attempting self-healing for failed checks', [
                'ticket_id' => $this->ticket->id,
                'failed_checks' => $failedChecks,
            ]);

            // Create failure bundle and dispatch repair job
            try {
                $failureCollector = app(FailureCollector::class);

                // Create a synthetic exception for the check failures
                $exception = new \Exception(
                    'Checks failed: '.implode(', ', $failedChecks)
                );

                $bundlePath = $failureCollector->captureFailure(
                    $exception,
                    $this->ticket,
                    'RunChecksJob',
                    [
                        'failed_checks' => $failedChecks,
                        'check_results' => $results,
                    ]
                );

                // Dispatch repair job
                RepairAttemptJob::dispatch($this->ticket, $bundlePath, 1)
                    ->delay(now()->addSeconds(10));

                Log::info('Self-healing initiated', [
                    'ticket_id' => $this->ticket->id,
                    'bundle_path' => $bundlePath,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to initiate self-healing', [
                    'ticket_id' => $this->ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RunChecksJob failed', [
            'ticket_id' => $this->ticket->id,
            'patch_id' => $this->patch->id,
            'error' => $exception->getMessage(),
        ]);

        // Capture failure for self-healing
        try {
            $failureCollector = app(FailureCollector::class);
            $bundlePath = $failureCollector->captureFailure(
                $exception,
                $this->ticket,
                'RunChecksJob',
                ['patch_id' => $this->patch->id]
            );

            // Check if we should attempt repair
            $repairAttempts = $this->ticket->workflow->meta['repair_attempts'] ?? 0;
            if ($repairAttempts < 2) {
                RepairAttemptJob::dispatch($this->ticket, $bundlePath, $repairAttempts + 1)
                    ->delay(now()->addSeconds(30));
            }
        } catch (\Exception $e) {
            Log::error('Failed to capture failure bundle', [
                'error' => $e->getMessage(),
            ]);
        }

        // Update workflow state
        if ($this->ticket->workflow) {
            $this->ticket->workflow->update([
                'state' => 'FAILED',
                'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                    'checks_job_failed' => true,
                    'checks_job_error' => $exception->getMessage(),
                ]),
            ]);
        }
    }
}
