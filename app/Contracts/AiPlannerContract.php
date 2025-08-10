<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\PlanJson;
use App\DTO\PlanningInputDto;

/**
 * Contract for AI-based planning services.
 */
interface AiPlannerContract
{
    /**
     * Generate an implementation plan using AI.
     *
     * @param  PlanningInputDto  $input  The planning input data
     * @return PlanJson The generated plan
     *
     * @throws \App\Exceptions\PlanningFailedException
     * @throws \App\Exceptions\AiServiceUnavailableException
     */
    public function plan(PlanningInputDto $input): PlanJson;
}
