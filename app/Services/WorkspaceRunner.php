<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\ProcessResultDto;
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
        'php' => 'synapticore/runner-php:latest',
        'node' => 'synapticore/runner-node:latest',
        'javascript' => 'synapticore/runner-node:latest',
        'typescript' => 'synapticore/runner-node:latest',
        'python' => 'synapticore/runner-python:latest',
        'go' => 'synapticore/runner-go:latest',
        'java' => 'synapticore/runner-java:latest',
        'kotlin' => 'synapticore/runner-java:latest',
        'ruby' => 'synapticore/runner-ruby:latest',
        'rust' => 'synapticore/runner-rust:latest',
        'csharp' => 'synapticore/runner-dotnet:latest',
    ];

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
     * Run command in secure Docker container.
     *
     * @param  array<string, string>  $env  Environment variables
     */
    public function run(
        string $workspacePath,
        string $command,
        string $language,
        array $env = [],
        int $timeout = 1800,
    ): ProcessResultDto {
        $runId = Str::uuid()->toString();

        Log::info('Starting workspace runner', [
            'run_id' => $runId,
            'command' => $command,
            'language' => $language,
            'workspace' => $workspacePath,
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
            $duration = now()->diffInSeconds($startTime);

            // Read output files
            $stdout = file_exists($stdoutFile) ? file_get_contents($stdoutFile) : '';
            $stderr = file_exists($stderrFile) ? file_get_contents($stderrFile) : '';

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
            '--network=isolated',  // Custom network with egress filtering
            '--user=1000:1000',    // Run as non-root user
            '--read-only',         // Read-only root filesystem
            '--tmpfs=/tmp',        // Writable temp directory
            '--memory=2g',         // Memory limit
            '--cpus=2',            // CPU limit
            '--security-opt=no-new-privileges',
            "-v={$workspacePath}:/workspace:rw",  // Mount workspace
        ];

        // Add environment variables
        foreach ($env as $key => $value) {
            $dockerCmd[] = "-e={$key}=".escapeshellarg($value);
        }

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
        $duration = now()->diffInSeconds($startTime);

        return new ProcessResultDto(
            exitCode: $result->exitCode() ?? 0,
            stdout: $result->output(),
            stderr: $result->errorOutput(),
            duration: $duration,
            timedOut: $result->exitCode() === Process::TIMEOUT_EXIT_CODE,
            signal: null,
            logPaths: [],
        );
    }
}
