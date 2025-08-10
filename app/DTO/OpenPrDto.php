<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for opening a pull request.
 */
#[Immutable]
final class OpenPrDto extends Data
{
    public function __construct(
        public readonly string $repositoryUrl,
        public readonly string $sourceBranch,
        public readonly string $targetBranch,
        public readonly string $title,
        public readonly string $body,
        public readonly array $labels = [],
        public readonly bool $isDraft = true,
        public readonly ?string $ticketKey = null,
        public readonly array $reviewers = [],
        public readonly array $assignees = [],
    ) {}
}
