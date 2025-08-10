<?php

declare(strict_types=1);

namespace App\Services\Runner;

use App\Contracts\RunnerContract;
use App\DTO\ProcessResultDto;

/**
 * Docker skeleton implementation of the command runner contract.
 */
class DockerRunner implements RunnerContract
{
    public function __construct(
        private readonly string $dockerSocket = '/var/run/docker.sock',
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function run(
        string $workspace,
        string $cmd,
        array $env = [],
        int $timeout = 1800
    ): ProcessResultDto {
        // TODO: Implement Docker command execution

        // Return placeholder result for now
        return new ProcessResultDto(
            exitCode: 0,
            stdout: 'Placeholder output - DockerRunner not yet implemented',
            stderr: '',
            executionTime: 0.1,
            command: $cmd,
            workspace: $workspace,
            timedOut: false,
            environment: $env,
            logPath: null,
        );
    }
}
