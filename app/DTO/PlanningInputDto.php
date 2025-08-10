<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for AI planner input.
 */
#[Immutable]
final class PlanningInputDto extends Data
{
    /**
     * @param  TicketDto  $ticket  The ticket to plan for
     * @param  array<array{content: string, relevance: float, source: string}>  $context  RAG context
     */
    public function __construct(
        public readonly TicketDto $ticket,
        public readonly array $context = [],
    ) {}

    /**
     * Get high relevance context items.
     *
     * @return array<array{content: string, relevance: float, source: string}>
     */
    public function getHighRelevanceContext(float $threshold = 0.7): array
    {
        return array_filter(
            $this->context,
            fn ($item) => ($item['relevance'] ?? 0) >= $threshold
        );
    }

    /**
     * Get context grouped by source.
     *
     * @return array<string, array>
     */
    public function getContextBySource(): array
    {
        $grouped = [];

        foreach ($this->context as $item) {
            $source = $item['source'] ?? 'unknown';
            if (! isset($grouped[$source])) {
                $grouped[$source] = [];
            }
            $grouped[$source][] = $item;
        }

        return $grouped;
    }
}
