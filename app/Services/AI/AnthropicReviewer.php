<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiReviewerContract;
use App\DTO\ReviewInputDto;
use App\DTO\ReviewResultDto;
use App\Exceptions\NotImplementedException;

/**
 * Anthropic skeleton implementation of the AI reviewer contract.
 */
class AnthropicReviewer implements AiReviewerContract
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
    public function review(ReviewInputDto $input): ReviewResultDto
    {
        throw new NotImplementedException('AnthropicReviewer::review() not yet implemented');
    }
}
