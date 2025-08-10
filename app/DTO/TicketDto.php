<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for ticket information.
 */
#[Immutable]
final class TicketDto extends Data
{
    public function __construct(
        public readonly string $externalKey,
        public readonly string $title,
        public readonly string $body,
        public readonly string $status,
        public readonly string $priority,
        public readonly string $source,
        public readonly array $labels = [],
        public readonly array $acceptanceCriteria = [],
        public readonly array $meta = [],
        public readonly ?string $assignee = null,
        public readonly ?string $reporter = null,
        public readonly ?int $storyPoints = null,
        public readonly ?string $sprint = null,
    ) {}
}
