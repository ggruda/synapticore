<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for AI planning input.
 */
#[Immutable]
final class PlanningInputDto extends Data
{
    public function __construct(
        public readonly TicketDto $ticket,
        public readonly string $repositoryPath,
        public readonly array $contextFiles = [],
        public readonly array $languageProfile = [],
        public readonly array $allowedPaths = [],
        public readonly ?string $additionalContext = null,
        public readonly array $constraints = [],
        public readonly ?int $maxSteps = null,
    ) {}
}
