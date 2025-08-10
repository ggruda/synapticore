<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for AI review input.
 */
#[Immutable]
final class ReviewInputDto extends Data
{
    public function __construct(
        public readonly TicketDto $ticket,
        public readonly PlanJson $plan,
        public readonly PatchSummaryJson $patch,
        public readonly string $repositoryPath,
        public readonly string $branch,
        public readonly array $testResults = [],
        public readonly array $lintResults = [],
        public readonly ?string $focusAreas = null,
        public readonly array $reviewCriteria = [],
    ) {}
}
