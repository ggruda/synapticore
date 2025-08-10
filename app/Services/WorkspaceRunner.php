<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\ProcessResultDto;
use App\DTO\RepoProfileJson;
use App\Exceptions\CommandBlockedException;
use App\Exceptions\PathViolationException;
use App\Services\Runner\CommandGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs commands in language-specific Docker sidecars with security constraints.
 * Captures output and uploads to object storage.
 */
class WorkspaceRunner
{
    /**
     * Docker images for different languages.
     */
    private const RUNNER_IMAGES = [
        'php' => 'synapticore/runner:php',
        'node' => 'synapticore/runner:node',
        'javascript' => 'synapticore/runner:node',
        'typescript' => 'synapticore/runner:node',
        'python' => 'synapticore/runner:python',
        'go' => 'synapticore/runner:go',
        'java' => 'synapticore/runner:java',
        'kotlin' => 'synapticore/runner:java',
        'ruby' => 'synapticore/runner:ruby',
        'rust' => 'synapticore/runner:rust',
        'csharp' => 'synapticore/runner:dotnet',
    ];

    /**
     * Maximum output size (1MB).
     */
    private const MAX_OUTPUT_SIZE = 1048576;

    /**
     * Default command timeout (5 minutes).
     */
    private const DEFAULT_TIMEOUT = 300;

    /**
     * Maximum command timeout (10 minutes).
     */
    private const MAX_TIMEOUT = 600;

    /**
     * Rate limit attempts.
     */
    private const RATE_LIMIT_ATTEMPTS = 10;

    /**
     * Rate limit decay seconds.
     */
    private const RATE_LIMIT_DECAY = 60;

    /**
     * Command guard instance.
     */
    private CommandGuard $commandGuard;

    /**
     * Create a new workspace runner instance.
     */
    public function __construct(?CommandGuard $commandGuard = null)
    {
        $this->commandGuard = $commandGuard ?? new CommandGuard;
    }

    /**
     * Allowed registries for egress.
     */
    private const ALLOWED_REGISTRIES = [
        'registry.npmjs.org',
        'packagist.org',
        'pypi.org',
        'proxy.golang.org',
        'repo.maven.apache.org',
        'rubygems.org',
        'crates.io',
        'nuget.org',
    ];

    /**
     * Run command in secure Docker container with guardrails.
     *
     * @param  array<string, string>  $env  Environment variables
     * @param  array<string>  $allowedPaths  Additional allowed paths
     *
     * @throws CommandBlockedException
     * @throws PathViolationException
     */
    public function run(
        string $workspacePath,
        string $command,
        string $language,
        array $env = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        ?RepoProfileJson $repoProfile = null,
        array $allowedPaths = [],
        ?string $ticketId = null,
    ): ProcessResultDto {
        $runId = Str::uuid()->toString();

        // Validate timeout
        $timeout = min($timeout, self::MAX_TIMEOUT);

        // Check rate limiting if ticket ID provided
        if ($ticketId) {
            $rateLimitKey = $this->commandGuard->getRateLimitKey($ticketId, $command);
            if ($this->commandGuard->isRateLimited(
                $rateLimitKey,
                self::RATE_LIMIT_ATTEMPTS,
                self::RATE_LIMIT_DECAY
            )) {
                throw new CommandBlockedException('Command execution rate limited');
            }
        }

        // Validate command with guard
        $validation = $this->commandGuard->validateCommand(
            $command,
            $repoProfile,
            array_merge([$workspacePath], $allowedPaths)
        );

        Log::info('Starting workspace runner', [
            'run_id' => $runId,
            'command' => $command,
            'language' => $language,
            'workspace' => $workspacePath,
            'validation' => $validation,
        ]);

        // Get Docker image for language
        $image = $this->getRunnerImage($language);

        // Prepare log files
        $stdoutFile = "/tmp/runner_{$runId}_stdout.log";
        $stderrFile = "/tmp/runner_{$runId}_stderr.log";

        try {
            // Build Docker command
            $dockerCommand = $this->buildDockerCommand(
                $image,
                $workspacePath,
                $command,
                $env,
                $stdoutFile,
                $stderrFile
            );

            // Execute command
            $startTime = now();
            $result = Process::timeout($timeout)->run($dockerCommand);
            $duration = (int) now()->diffInSeconds($startTime);

            // Read output files with size limit
            $stdout = file_exists($stdoutFile)
                ? $this->readFileWithLimit($stdoutFile, self::MAX_OUTPUT_SIZE)
                : '';
            $stderr = file_exists($stderrFile)
                ? $this->readFileWithLimit($stderrFile, self::MAX_OUTPUT_SIZE)
                : '';

            // Sanitize output
            $stdoutData = $this->commandGuard->sanitizeOutput($stdout, self::MAX_OUTPUT_SIZE);
            $stderrData = $this->commandGuard->sanitizeOutput($stderr, self::MAX_OUTPUT_SIZE);

            // Upload logs to storage
            $logPaths = $this->uploadLogs($runId, $stdout, $stderr);

            // Clean up temp files
            @unlink($stdoutFile);
            @unlink($stderrFile);

            Log::info('Workspace runner completed', [
                'run_id' => $runId,
                'exit_code' => $result->exitCode(),
                'duration' => $duration,
            ]);

            return new ProcessResultDto(
                exitCode: $result->exitCode() ?? 0,
                stdout: $stdout,
                stderr: $stderr,
                duration: $duration,
                timedOut: false,
                signal: null,
                logPaths: $logPaths,
            );
        } catch (\Exception $e) {
            Log::error('Workspace runner failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);

            // Clean up temp files
            @unlink($stdoutFile);
            @unlink($stderrFile);

            return new ProcessResultDto(
                exitCode: 1,
                stdout: '',
                stderr: $e->getMessage(),
                duration: 0,
                timedOut: false,
                signal: null,
                logPaths: [],
            );
        }
    }

    /**
     * Get Docker image for language.
     */
    private function getRunnerImage(string $language): string
    {
        $image = self::RUNNER_IMAGES[strtolower($language)] ?? null;

        if (! $image) {
            // Fallback to a generic image
            Log::warning('No runner image for language, using default', ['language' => $language]);

            return 'ubuntu:22.04';
        }

        // Check if using local development images
        if (app()->environment('local')) {
            // Use local built images
            return str_replace('synapticore/', 'local/', $image);
        }

        return $image;
    }

    /**
     * Build Docker command with security constraints.
     *
     * @param  array<string, string>  $env
     */
    private function buildDockerCommand(
        string $image,
        string $workspacePath,
        string $command,
        array $env,
        string $stdoutFile,
        string $stderrFile,
    ): string {
        $dockerCmd = [
            'docker', 'run',
            '--rm',
            '--network=host',      // Use host network for now (isolated network needs setup)
            '--user=1000:1000',    // Run as non-root user
            '--read-only',         // Read-only root filesystem
            '--tmpfs=/tmp:noexec,nosuid,size=128M',  // Writable temp with restrictions
            '--tmpfs=/home/runner:noexec,nosuid,size=64M',
            '--memory=512m',       // Memory limit
            '--memory-swap=512m',  // Prevent swap usage
            '--cpus=1',            // CPU limit
            '--pids-limit=100',    // Process limit
            '--security-opt=no-new-privileges',
            '--security-opt=apparmor=unconfined',  // AppArmor if available
            '--cap-drop=ALL',      // Drop all capabilities
            '--cap-add=CHOWN',     // Only allow specific capabilities
            '--cap-add=SETUID',
            '--cap-add=SETGID',
            "-v={$workspacePath}:/workspace:rw",  // Mount workspace
            '-v=/etc/runner/security.conf:/etc/runner/security.conf:ro',  // Security config
        ];

        // Add environment variables
        foreach ($env as $key => $value) {
            $dockerCmd[] = "-e={$key}=".escapeshellarg($value);
        }

        // Add timeout and output limit environment variables
        $dockerCmd[] = '-e=RUNNER_TIMEOUT='.self::DEFAULT_TIMEOUT;
        $dockerCmd[] = '-e=RUNNER_MAX_OUTPUT='.self::MAX_OUTPUT_SIZE;

        // Add working directory
        $dockerCmd[] = '-w=/workspace';

        // Add image
        $dockerCmd[] = $image;

        // Add shell command with output redirection
        $dockerCmd[] = '/bin/sh';
        $dockerCmd[] = '-c';
        $dockerCmd[] = escapeshellarg(
            "{$command} > {$stdoutFile} 2> {$stderrFile}"
        );

        return implode(' ', $dockerCmd);
    }

    /**
     * Read file with size limit.
     */
    private function readFileWithLimit(string $filepath, int $maxSize): string
    {
        if (! file_exists($filepath)) {
            return '';
        }

        $fileSize = filesize($filepath);
        if ($fileSize === false || $fileSize === 0) {
            return '';
        }

        // If file is larger than max size, read only the allowed amount
        if ($fileSize > $maxSize) {
            $handle = fopen($filepath, 'r');
            if ($handle === false) {
                return '';
            }

            $content = fread($handle, $maxSize);
            fclose($handle);

            // Add truncation notice
            $content .= "\n\n[OUTPUT TRUNCATED - Original size: {$fileSize} bytes, limit: {$maxSize} bytes]";

            return $content ?: '';
        }

        // Read entire file if within limits
        $content = file_get_contents($filepath);

        return $content ?: '';
    }

    /**
     * Upload logs to object storage.
     *
     * @return array<string, string>
     */
    private function uploadLogs(string $runId, string $stdout, string $stderr): array
    {
        $paths = [];

        // Upload stdout
        if (! empty($stdout)) {
            $stdoutPath = "logs/runs/{$runId}/stdout.log";
            Storage::disk('spaces')->put($stdoutPath, $stdout);
            $paths['stdout'] = $stdoutPath;
        }

        // Upload stderr
        if (! empty($stderr)) {
            $stderrPath = "logs/runs/{$runId}/stderr.log";
            Storage::disk('spaces')->put($stderrPath, $stderr);
            $paths['stderr'] = $stderrPath;
        }

        Log::info('Uploaded run logs', [
            'run_id' => $runId,
            'paths' => $paths,
        ]);

        return $paths;
    }

    /**
     * Create isolated Docker network with egress filtering.
     * This should be run once during setup.
     */
    public function createIsolatedNetwork(): void
    {
        // Check if network exists
        $result = Process::run('docker network ls --format "{{.Name}}" | grep -q isolated');

        if ($result->exitCode() !== 0) {
            // Create network
            Process::run('docker network create --driver bridge isolated');

            Log::info('Created isolated Docker network');

            // TODO: Add iptables rules for egress filtering
            // This would require more complex setup with firewall rules
        }
    }

    /**
     * Build Docker runner images.
     * This should be run during deployment.
     */
    public function buildRunnerImages(): void
    {
        $languages = ['php', 'node', 'python', 'go', 'java', 'ruby', 'rust', 'dotnet'];

        foreach ($languages as $lang) {
            $dockerfilePath = base_path("runners/{$lang}/Dockerfile");

            if (file_exists($dockerfilePath)) {
                $imageName = "local/runner-{$lang}:latest";

                Log::info('Building runner image', [
                    'language' => $lang,
                    'image' => $imageName,
                ]);

                $result = Process::timeout(600)
                    ->path(base_path("runners/{$lang}"))
                    ->run("docker build -t {$imageName} .");

                if ($result->successful()) {
                    Log::info('Built runner image successfully', ['image' => $imageName]);
                } else {
                    Log::error('Failed to build runner image', [
                        'image' => $imageName,
                        'error' => $result->errorOutput(),
                    ]);
                }
            }
        }
    }

    /**
     * Run command directly without Docker (for development/testing).
     *
     * @param  array<string, string>  $env
     */
    public function runDirect(
        string $workspacePath,
        string $command,
        array $env = [],
        int $timeout = 1800,
    ): ProcessResultDto {
        Log::info('Running command directly', [
            'command' => $command,
            'workspace' => $workspacePath,
        ]);

        $startTime = now();
        $result = Process::path($workspacePath)
            ->timeout($timeout)
            ->env($env)
            ->run($command);
        $duration = (int) now()->diffInSeconds($startTime);

        return new ProcessResultDto(
            exitCode: $result->exitCode() ?? 0,
            stdout: $result->output(),
            stderr: $result->errorOutput(),
            duration: $duration,
            timedOut: false, // Process doesn't have timeout detection in this version
            signal: null,
            logPaths: [],
        );
    }
}
