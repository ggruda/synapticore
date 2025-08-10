<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiReviewerContract;
use App\DTO\ReviewInputDto;
use App\DTO\ReviewResultDto;
use App\Exceptions\NotImplementedException;

/**
 * Azure OpenAI skeleton implementation of the AI reviewer contract.
 */
class AzureOpenAiReviewer implements AiReviewerContract
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
    public function review(ReviewInputDto $input): ReviewResultDto
    {
        throw new NotImplementedException('AzureOpenAiReviewer::review() not yet implemented');
    }
}
