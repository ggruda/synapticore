<?php

declare(strict_types=1);

namespace App\Services\Runner;

use App\Contracts\RunnerContract;
use App\DTO\ProcessResultDto;
use App\Exceptions\NotImplementedException;

/**
 * Local skeleton implementation of the command runner contract.
 */
class LocalRunner implements RunnerContract
{
    public function __construct()
    {
        // Constructor for any future dependencies
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
        throw new NotImplementedException('LocalRunner::run() not yet implemented');
    }
}
