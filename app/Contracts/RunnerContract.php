<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\ProcessResultDto;

/**
 * Contract for command execution services.
 */
interface RunnerContract
{
    /**
     * Execute a command in a specific workspace.
     *
     * @param  string  $workspace  The workspace directory path
     * @param  string  $cmd  The command to execute
     * @param  array<string, string>  $env  Environment variables
     * @param  int  $timeout  Timeout in seconds (default: 1800)
     * @return ProcessResultDto The process execution result
     *
     * @throws \App\Exceptions\CommandExecutionException
     * @throws \App\Exceptions\TimeoutException
     */
    public function run(
        string $workspace,
        string $cmd,
        array $env = [],
        int $timeout = 1800
    ): ProcessResultDto;
}
