<?php

declare(strict_types=1);

namespace App\Services\Runner;

use App\Contracts\RunnerContract;
use App\DTO\ProcessResultDto;
use App\Exceptions\NotImplementedException;

/**
 * Kubernetes skeleton implementation of the command runner contract.
 */
class KubernetesRunner implements RunnerContract
{
    public function __construct(
        private readonly string $namespace = 'default',
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
        throw new NotImplementedException('KubernetesRunner::run() not yet implemented');
    }
}
