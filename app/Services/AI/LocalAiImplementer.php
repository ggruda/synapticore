<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiImplementerContract;
use App\DTO\ImplementInputDto;
use App\DTO\PatchSummaryJson;
use App\Exceptions\NotImplementedException;

/**
 * Local AI skeleton implementation of the AI implementer contract.
 */
class LocalAiImplementer implements AiImplementerContract
{
    public function __construct(
        private readonly string $endpoint,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function implement(ImplementInputDto $input): PatchSummaryJson
    {
        throw new NotImplementedException('LocalAiImplementer::implement() not yet implemented');
    }
}
