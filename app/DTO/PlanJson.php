<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for AI-generated plan.
 */
#[Immutable]
final class PlanJson extends Data
{
    public function __construct(
        public readonly array $steps,
        public readonly string $testStrategy,
        public readonly string $risk,
        public readonly float $estimatedHours,
        public readonly array $dependencies = [],
        public readonly array $filesAffected = [],
        public readonly array $breakingChanges = [],
        public readonly ?string $summary = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Get risk level as enum value.
     */
    public function riskLevel(): string
    {
        return match ($this->risk) {
            'low', 'medium', 'high', 'critical' => $this->risk,
            default => 'medium',
        };
    }
}
