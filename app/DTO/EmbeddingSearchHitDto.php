<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for embedding search results.
 */
#[Immutable]
final class EmbeddingSearchHitDto extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly float $score,
        public readonly float $distance,
        public readonly array $metadata = [],
        public readonly ?string $source = null,
        public readonly ?array $highlights = null,
        public readonly ?array $vector = null,
    ) {}

    /**
     * Check if this is a high-confidence match.
     */
    public function isHighConfidence(float $threshold = 0.8): bool
    {
        return $this->score >= $threshold;
    }

    /**
     * Get similarity percentage.
     */
    public function similarityPercentage(): float
    {
        return round($this->score * 100, 2);
    }
}
