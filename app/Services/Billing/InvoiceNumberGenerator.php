<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating unique invoice numbers.
 */
class InvoiceNumberGenerator
{
    /**
     * Generate a new invoice number for a project.
     *
     * Format: SC-YYYYMM-SEQ or custom format from config
     * e.g., SC-202501-001
     */
    public function generate(Project $project, Carbon $date): string
    {
        $format = config('billing.invoice_number_format', '{PREFIX}-{YYYY}{MM}-{SEQ}');
        $prefix = config('billing.invoice_number_prefix', 'SC');

        // Get the next sequence number for this month
        $sequence = $this->getNextSequence($date);

        // Build replacements
        $replacements = [
            '{PREFIX}' => $prefix,
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{M}' => $date->format('n'),
            '{PROJECT}' => strtoupper(substr($project->name, 0, 3)),
            '{SEQ}' => str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            '{SEQ2}' => str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            '{SEQ4}' => str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        ];

        // Replace tokens in format
        $invoiceNumber = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $format
        );

        // Ensure uniqueness
        if ($this->exists($invoiceNumber)) {
            Log::warning('Invoice number collision detected, regenerating', [
                'number' => $invoiceNumber,
                'project' => $project->name,
            ]);

            // Try with incremented sequence
            return $this->generate($project, $date);
        }

        Log::info('Generated invoice number', [
            'number' => $invoiceNumber,
            'project' => $project->name,
            'date' => $date->format('Y-m'),
            'sequence' => $sequence,
        ]);

        return $invoiceNumber;
    }

    /**
     * Get the next sequence number for a given month.
     */
    private function getNextSequence(Carbon $date): int
    {
        // Lock to prevent race conditions
        return DB::transaction(function () use ($date) {
            $startOfMonth = $date->copy()->startOfMonth();
            $endOfMonth = $date->copy()->endOfMonth();

            // Find the highest sequence number for this month
            $lastInvoice = Invoice::whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            if (! $lastInvoice) {
                return 1;
            }

            // Extract sequence from invoice number
            $lastSequence = $this->extractSequence($lastInvoice->invoice_number);

            return $lastSequence + 1;
        });
    }

    /**
     * Extract sequence number from an invoice number.
     */
    private function extractSequence(string $invoiceNumber): int
    {
        // Try to extract the last numeric part
        if (preg_match('/(\d+)(?!.*\d)/', $invoiceNumber, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Check if an invoice number already exists.
     */
    private function exists(string $invoiceNumber): bool
    {
        return Invoice::where('invoice_number', $invoiceNumber)->exists();
    }

    /**
     * Validate a proposed invoice number format.
     */
    public function validateFormat(string $format): array
    {
        $errors = [];
        $warnings = [];

        // Check for required tokens
        if (! str_contains($format, '{SEQ')) {
            $errors[] = 'Format must contain a sequence token ({SEQ}, {SEQ2}, or {SEQ4})';
        }

        // Check for date components
        if (! str_contains($format, '{YYYY}') && ! str_contains($format, '{YY}')) {
            $warnings[] = 'Format does not contain a year component';
        }

        if (! str_contains($format, '{MM}') && ! str_contains($format, '{M}')) {
            $warnings[] = 'Format does not contain a month component';
        }

        // Test format with sample data
        try {
            $testProject = new Project(['name' => 'TEST']);
            $testDate = Carbon::now();
            $sample = $this->generateSample($format, $testProject, $testDate, 1);

            if (strlen($sample) > 50) {
                $warnings[] = 'Generated invoice numbers may be very long';
            }
        } catch (\Exception $e) {
            $errors[] = 'Invalid format: '.$e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Generate a sample invoice number for preview.
     */
    public function generateSample(
        string $format,
        Project $project,
        Carbon $date,
        int $sequence = 1
    ): string {
        $prefix = config('billing.invoice_number_prefix', 'SC');

        $replacements = [
            '{PREFIX}' => $prefix,
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{M}' => $date->format('n'),
            '{PROJECT}' => strtoupper(substr($project->name, 0, 3)),
            '{SEQ}' => str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            '{SEQ2}' => str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            '{SEQ4}' => str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $format
        );
    }

    /**
     * Get statistics about invoice numbering.
     */
    public function getStatistics(): array
    {
        $currentMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();

        return [
            'total_invoices' => Invoice::count(),
            'current_month_count' => Invoice::whereBetween('created_at', [
                $currentMonth,
                $currentMonth->copy()->endOfMonth(),
            ])->count(),
            'last_month_count' => Invoice::whereBetween('created_at', [
                $lastMonth,
                $lastMonth->copy()->endOfMonth(),
            ])->count(),
            'last_invoice_number' => Invoice::orderBy('id', 'desc')->value('invoice_number'),
            'format' => config('billing.invoice_number_format'),
            'prefix' => config('billing.invoice_number_prefix'),
        ];
    }
}
