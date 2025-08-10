<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for process execution results.
 */
#[Immutable]
final class ProcessResultDto extends Data
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $executionTime,
        public readonly string $command,
        public readonly string $workspace,
        public readonly bool $timedOut = false,
        public readonly array $environment = [],
        public readonly ?string $logPath = null,
    ) {}

    /**
     * Check if the process was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    /**
     * Get combined output.
     */
    public function getCombinedOutput(): string
    {
        return trim($this->stdout.PHP_EOL.$this->stderr);
    }

    /**
     * Check if there are errors.
     */
    public function hasErrors(): bool
    {
        return ! empty($this->stderr) || $this->exitCode !== 0;
    }
}
