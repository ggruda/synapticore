<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for AI reviewer input.
 */
#[Immutable]
final class ReviewInputDto extends Data
{
    /**
     * @param  PatchSummaryJson  $patch  The patch to review
     * @param  array  $testResults  Test execution results
     * @param  bool  $checksPass  Whether mandatory checks passed
     * @param  array<string>  $policyViolations  Policy violations found
     */
    public function __construct(
        public readonly PatchSummaryJson $patch,
        public readonly array $testResults = [],
        public readonly bool $checksPass = true,
        public readonly array $policyViolations = [],
    ) {}

    /**
     * Check if there are test failures.
     */
    public function hasTestFailures(): bool
    {
        foreach ($this->testResults as $result) {
            if (($result['status'] ?? '') === 'failed') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get test coverage if available.
     */
    public function getTestCoverage(): ?float
    {
        foreach ($this->testResults as $result) {
            if ($result['type'] === 'test' && isset($result['coverage'])) {
                return (float) $result['coverage'];
            }
        }

        return null;
    }

    /**
     * Check if review should be strict.
     */
    public function requiresStrictReview(): bool
    {
        // Strict if there are policy violations
        if (! empty($this->policyViolations)) {
            return true;
        }

        // Strict if checks failed
        if (! $this->checksPass) {
            return true;
        }

        // Strict if high risk
        if ($this->patch->riskScore > 60) {
            return true;
        }

        return false;
    }
}
