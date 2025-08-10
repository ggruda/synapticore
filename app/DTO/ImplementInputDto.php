<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for AI implementation input.
 */
#[Immutable]
final class ImplementInputDto extends Data
{
    public function __construct(
        public readonly TicketDto $ticket,
        public readonly PlanJson $plan,
        public readonly string $repositoryPath,
        public readonly string $branch,
        public readonly array $contextFiles = [],
        public readonly array $existingPatches = [],
        public readonly ?string $additionalInstructions = null,
        public readonly array $constraints = [],
    ) {}
}
