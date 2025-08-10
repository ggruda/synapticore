<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiPlannerContract;
use App\DTO\PlanJson;
use App\DTO\PlanningInputDto;
use App\Exceptions\NotImplementedException;

/**
 * Anthropic skeleton implementation of the AI planner contract.
 */
class AnthropicPlanner implements AiPlannerContract
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
    public function plan(PlanningInputDto $input): PlanJson
    {
        throw new NotImplementedException('AnthropicPlanner::plan() not yet implemented');
    }
}
