<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\ImplementInputDto;
use App\DTO\PatchSummaryJson;

/**
 * Contract for AI-based implementation services.
 */
interface AiImplementerContract
{
    /**
     * Generate implementation patches using AI.
     *
     * @param  ImplementInputDto  $input  The implementation input data
     * @return PatchSummaryJson The generated patch summary
     *
     * @throws \App\Exceptions\ImplementationFailedException
     * @throws \App\Exceptions\AiServiceUnavailableException
     */
    public function implement(ImplementInputDto $input): PatchSummaryJson;
}
