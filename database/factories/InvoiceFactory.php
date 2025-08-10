<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-3 months', '-1 month');
        $periodEnd = (clone $periodStart)->modify('+1 month')->modify('-1 day');
        $netTotal = fake()->randomFloat(2, 1000, 50000);
        $taxRate = fake()->randomElement([0, 2.5, 7.7, 8.1]); // Swiss VAT rates
        $grossTotal = $netTotal * (1 + $taxRate / 100);

        return [
            'project_id' => Project::factory(),
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
            'currency' => fake()->randomElement(['CHF', 'EUR', 'USD']),
            'net_total' => $netTotal,
            'tax_rate' => $taxRate,
            'gross_total' => round($grossTotal, 2),
            'number' => fake()->unique()->regexify('INV-[0-9]{4}-[0-9]{6}'),
            'status' => fake()->randomElement([
                Invoice::STATUS_DRAFT,
                Invoice::STATUS_SENT,
                Invoice::STATUS_PAID,
            ]),
            'pdf_path' => fake()->optional(0.7)->regexify('invoices/[0-9]{4}/[0-9]{2}/INV-[0-9]{10}.pdf'),
            'meta' => [
                'payment_terms' => fake()->randomElement([15, 30, 45, 60]),
                'payment_method' => fake()->randomElement(['bank_transfer', 'credit_card', 'paypal']),
                'notes' => fake()->optional()->sentence(),
                'client' => [
                    'name' => fake()->company(),
                    'address' => fake()->address(),
                    'vat_number' => fake()->optional()->regexify('CHE-[0-9]{3}.[0-9]{3}.[0-9]{3}'),
                ],
            ],
        ];
    }

    /**
     * Indicate that the invoice is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_DRAFT,
            'pdf_path' => null,
        ]);
    }

    /**
     * Indicate that the invoice has been sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_SENT,
            'pdf_path' => 'invoices/'.date('Y/m').'/'.$attributes['number'].'.pdf',
        ]);
    }

    /**
     * Indicate that the invoice has been paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_PAID,
            'pdf_path' => 'invoices/'.date('Y/m').'/'.$attributes['number'].'.pdf',
            'meta' => array_merge($attributes['meta'] ?? [], [
                'paid_at' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
                'payment_reference' => fake()->regexify('[A-Z0-9]{20}'),
            ]),
        ]);
    }
}
