<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiImplementerContract;
use App\DTO\ImplementInputDto;
use App\DTO\PatchSummaryJson;
use App\Exceptions\NotImplementedException;

/**
 * Azure OpenAI skeleton implementation of the AI implementer contract.
 */
class AzureOpenAiImplementer implements AiImplementerContract
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $deployment,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function implement(ImplementInputDto $input): PatchSummaryJson
    {
        throw new NotImplementedException('AzureOpenAiImplementer::implement() not yet implemented');
    }
}
