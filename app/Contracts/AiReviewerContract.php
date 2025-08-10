<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\ReviewInputDto;
use App\DTO\ReviewResultDto;

/**
 * Contract for AI-based code review services.
 */
interface AiReviewerContract
{
    /**
     * Review code changes using AI.
     *
     * @param  ReviewInputDto  $input  The review input data
     * @return ReviewResultDto The review results
     *
     * @throws \App\Exceptions\ReviewFailedException
     * @throws \App\Exceptions\AiServiceUnavailableException
     */
    public function review(ReviewInputDto $input): ReviewResultDto;
}
