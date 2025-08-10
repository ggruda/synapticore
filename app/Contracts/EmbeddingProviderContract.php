<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\EmbeddingSearchHitDto;
use App\DTO\VectorDto;

/**
 * Contract for embedding and vector search providers.
 */
interface EmbeddingProviderContract
{
    /**
     * Generate embeddings for text chunks.
     *
     * @param  array<string>  $chunks  Array of text chunks to embed
     * @return array<VectorDto> Array of vector representations
     *
     * @throws \App\Exceptions\EmbeddingGenerationException
     * @throws \App\Exceptions\ProviderConnectionException
     */
    public function embedChunks(array $chunks): array;

    /**
     * Search for similar embeddings.
     *
     * @param  string  $query  The search query
     * @param  int  $k  Number of results to return (default: 20)
     * @return array<EmbeddingSearchHitDto> Array of search results
     *
     * @throws \App\Exceptions\SearchFailedException
     * @throws \App\Exceptions\ProviderConnectionException
     */
    public function search(string $query, int $k = 20): array;
}
