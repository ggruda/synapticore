<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiPlannerContract;
use App\DTO\PlanJson;
use App\DTO\PlanningInputDto;
use App\Exceptions\NotImplementedException;

/**
 * Local AI skeleton implementation of the AI planner contract.
 */
class LocalAiPlanner implements AiPlannerContract
{
    public function __construct(
        private readonly string $endpoint,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function plan(PlanningInputDto $input): PlanJson
    {
        throw new NotImplementedException('LocalAiPlanner::plan() not yet implemented');
    }
}
