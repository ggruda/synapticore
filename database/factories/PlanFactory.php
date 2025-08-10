<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'plan_json' => [
                'steps' => [
                    [
                        'order' => 1,
                        'description' => 'Analyze requirements',
                        'files' => fake()->randomElements(['app/Models/User.php', 'app/Http/Controllers/UserController.php'], 2),
                    ],
                    [
                        'order' => 2,
                        'description' => 'Implement feature',
                        'files' => fake()->randomElements(['app/Services/UserService.php', 'tests/Feature/UserTest.php'], 2),
                    ],
                    [
                        'order' => 3,
                        'description' => 'Write tests',
                        'files' => ['tests/Unit/UserServiceTest.php'],
                    ],
                ],
                'estimated_hours' => fake()->randomFloat(1, 0.5, 8),
                'dependencies' => fake()->randomElements(['database', 'cache', 'queue', 'api'], 2),
            ],
            'risk' => fake()->randomElement([Plan::RISK_LOW, Plan::RISK_MEDIUM, Plan::RISK_HIGH, Plan::RISK_CRITICAL]),
            'test_strategy' => fake()->paragraph(),
        ];
    }

    /**
     * Indicate that the plan is low risk.
     */
    public function lowRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk' => Plan::RISK_LOW,
        ]);
    }

    /**
     * Indicate that the plan is high risk.
     */
    public function highRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk' => Plan::RISK_HIGH,
        ]);
    }
}
