<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiPlannerContract;
use App\DTO\PlanJson;
use App\DTO\PlanningInputDto;
use App\Exceptions\NotImplementedException;

/**
 * Azure OpenAI skeleton implementation of the AI planner contract.
 */
class AzureOpenAiPlanner implements AiPlannerContract
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
    public function plan(PlanningInputDto $input): PlanJson
    {
        throw new NotImplementedException('AzureOpenAiPlanner::plan() not yet implemented');
    }
}
