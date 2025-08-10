<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AiPlannerContract;
use App\DTO\PlanJson;
use App\DTO\PlanningInputDto;

/**
 * OpenAI skeleton implementation of the AI planner contract.
 */
class OpenAiPlanner implements AiPlannerContract
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
    public function plan(PlanningInputDto $input): PlanJson
    {
        // TODO: Implement OpenAI API integration for planning

        // Return placeholder plan for now
        return new PlanJson(
            steps: ['Step 1: Analyze requirements', 'Step 2: Implement solution', 'Step 3: Test'],
            testStrategy: 'Unit and integration testing',
            risk: 'medium',
            estimatedHours: 4.0,
            dependencies: [],
            filesAffected: [],
            breakingChanges: [],
            summary: 'Placeholder plan - not yet implemented',
            metadata: ['status' => 'skeleton'],
        );
    }
}
