<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiReviewerContract;
use App\DTO\ReviewInputDto;
use App\DTO\ReviewResultDto;
use App\Exceptions\NotImplementedException;

/**
 * Local AI skeleton implementation of the AI reviewer contract.
 */
class LocalAiReviewer implements AiReviewerContract
{
    public function __construct(
        private readonly string $endpoint,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function review(ReviewInputDto $input): ReviewResultDto
    {
        throw new NotImplementedException('LocalAiReviewer::review() not yet implemented');
    }
}
