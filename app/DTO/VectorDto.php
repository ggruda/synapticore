<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for vector embeddings.
 */
#[Immutable]
final class VectorDto extends Data
{
    public function __construct(
        public readonly string $content,
        public readonly array $vector,
        public readonly ?string $id = null,
        public readonly array $metadata = [],
        public readonly ?int $dimensions = null,
    ) {}

    /**
     * Get the vector dimensions.
     */
    public function getDimensions(): int
    {
        return $this->dimensions ?? count($this->vector);
    }

    /**
     * Convert vector to string representation.
     */
    public function vectorToString(): string
    {
        return '['.implode(',', $this->vector).']';
    }
}
