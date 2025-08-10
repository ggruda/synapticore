<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiImplementerContract;
use App\DTO\ImplementInputDto;
use App\DTO\PatchSummaryJson;

/**
 * OpenAI skeleton implementation of the AI implementer contract.
 */
class OpenAiImplementer implements AiImplementerContract
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4-turbo-preview',
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function implement(ImplementInputDto $input): PatchSummaryJson
    {
        // TODO: Implement OpenAI API integration for code implementation

        // Return placeholder patch summary for now
        return new PatchSummaryJson(
            filesTouched: ['placeholder.php'],
            diffStats: ['additions' => 0, 'deletions' => 0],
            riskScore: 50,
            summary: 'Placeholder implementation - not yet implemented',
            changes: [],
            breakingChanges: false,
            requiresMigration: false,
            testCoverage: null,
            metadata: ['status' => 'skeleton'],
        );
    }
}
