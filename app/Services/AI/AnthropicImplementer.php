<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiImplementerContract;
use App\DTO\ImplementInputDto;
use App\DTO\PatchSummaryJson;
use App\Exceptions\NotImplementedException;

/**
 * Anthropic skeleton implementation of the AI implementer contract.
 */
class AnthropicImplementer implements AiImplementerContract
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-3-opus-20240229',
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function implement(ImplementInputDto $input): PatchSummaryJson
    {
        throw new NotImplementedException('AnthropicImplementer::implement() not yet implemented');
    }
}
