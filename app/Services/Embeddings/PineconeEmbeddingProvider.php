<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingProviderContract;
use App\Exceptions\NotImplementedException;

/**
 * Pinecone skeleton implementation of the embedding provider contract.
 */
class PineconeEmbeddingProvider implements EmbeddingProviderContract
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $environment,
        private readonly string $index,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function embedChunks(array $chunks): array
    {
        throw new NotImplementedException('PineconeEmbeddingProvider::embedChunks() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function search(string $query, int $k = 20): array
    {
        throw new NotImplementedException('PineconeEmbeddingProvider::search() not yet implemented');
    }
}
