<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for created pull request information.
 */
#[Immutable]
final class PrCreatedDto extends Data
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $url,
        public readonly string $number,
        public readonly string $state,
        public readonly string $sourceBranch,
        public readonly string $targetBranch,
        public readonly bool $isDraft,
        public readonly ?string $mergeCommitSha = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $mergedAt = null,
        public readonly array $labels = [],
    ) {}
}
