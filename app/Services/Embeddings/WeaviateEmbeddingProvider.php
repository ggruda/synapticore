<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingProviderContract;
use App\Exceptions\NotImplementedException;

/**
 * Weaviate skeleton implementation of the embedding provider contract.
 */
class WeaviateEmbeddingProvider implements EmbeddingProviderContract
{
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function embedChunks(array $chunks): array
    {
        throw new NotImplementedException('WeaviateEmbeddingProvider::embedChunks() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function search(string $query, int $k = 20): array
    {
        throw new NotImplementedException('WeaviateEmbeddingProvider::search() not yet implemented');
    }
}
