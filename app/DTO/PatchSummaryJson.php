<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for patch summary.
 */
#[Immutable]
final class PatchSummaryJson extends Data
{
    public function __construct(
        public readonly array $filesTouched,
        public readonly array $diffStats,
        public readonly int $riskScore,
        public readonly string $summary,
        public readonly array $changes = [],
        public readonly bool $breakingChanges = false,
        public readonly bool $requiresMigration = false,
        public readonly ?float $testCoverage = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Calculate total lines changed.
     */
    public function totalLinesChanged(): int
    {
        return ($this->diffStats['additions'] ?? 0) + ($this->diffStats['deletions'] ?? 0);
    }
}
