<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiReviewerContract;
use App\DTO\ReviewInputDto;
use App\DTO\ReviewResultDto;

/**
 * OpenAI skeleton implementation of the AI reviewer contract.
 */
class OpenAiReviewer implements AiReviewerContract
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
    public function review(ReviewInputDto $input): ReviewResultDto
    {
        // TODO: Implement OpenAI API integration for code review

        // Return placeholder review result for now
        return new ReviewResultDto(
            status: ReviewResultDto::STATUS_APPROVED,
            issues: [],
            suggestions: ['Consider adding more tests'],
            approvals: ['Code structure looks good'],
            qualityScore: 75,
            summary: 'Placeholder review - not yet implemented',
            securityIssues: [],
            performanceIssues: [],
            metadata: ['status' => 'skeleton'],
        );
    }
}
