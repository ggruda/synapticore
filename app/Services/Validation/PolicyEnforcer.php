<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\DTO\PatchSummaryJson;
use App\DTO\PlanJson;
use App\DTO\ReviewResultDto;
use App\Services\WorkspaceRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Service for enforcing policies and generating risk assessments.
 */
class PolicyEnforcer
{
    private array $policies;

    private array $securityPolicies;

    public function __construct(
        private readonly WorkspaceRunner $runner,
    ) {
        $this->policies = config('synaptic.policies', []);
        $this->loadSecurityPolicies();
    }

    /**
     * Check if a plan complies with policies.
     */
    public function checkPlanCompliance(PlanJson $plan): PolicyCheckResult
    {
        $result = new PolicyCheckResult;

        // Check limits
        $this->checkPlanLimits($plan, $result);

        // Check allowed paths
        $this->checkAllowedPaths($plan->filesAffected ?? [], $result);

        // Calculate risk score
        $riskScore = $this->calculatePlanRiskScore($plan);
        $result->riskScore = $riskScore;
        $result->riskLevel = $this->getRiskLevel($riskScore);

        // Check if retry is needed
        if (! $result->passed && $result->retryable) {
            $result->retryReason = 'Policy violations can be fixed: '.implode('; ', $result->violations);
        }

        return $result;
    }

    /**
     * Check if a patch complies with policies.
     */
    public function checkPatchCompliance(PatchSummaryJson $patch): PolicyCheckResult
    {
        $result = new PolicyCheckResult;

        // Check limits
        $this->checkPatchLimits($patch, $result);

        // Run mandatory checks
        $this->runMandatoryChecks($patch, $result);

        // Run security scans
        $this->runSecurityScans($patch, $result);

        // Calculate risk score
        $riskScore = $this->calculatePatchRiskScore($patch);
        $result->riskScore = $riskScore;
        $result->riskLevel = $this->getRiskLevel($riskScore);

        // Generate review checklist
        $result->reviewChecklist = $this->generateReviewChecklist($patch, $result->riskLevel);

        return $result;
    }

    /**
     * Generate a review result based on policy checks.
     */
    public function generateReviewResult(
        PatchSummaryJson $patch,
        PolicyCheckResult $policyResult,
    ): ReviewResultDto {
        $issues = [];
        $suggestions = [];

        // Add policy violations as issues
        foreach ($policyResult->violations as $violation) {
            $issues[] = [
                'type' => 'policy_violation',
                'severity' => 'high',
                'message' => $violation,
            ];
        }

        // Add warnings as suggestions
        foreach ($policyResult->warnings as $warning) {
            $suggestions[] = [
                'type' => 'improvement',
                'message' => $warning,
            ];
        }

        // Add security findings
        foreach ($policyResult->securityFindings as $finding) {
            $issues[] = [
                'type' => 'security',
                'severity' => $finding['severity'] ?? 'medium',
                'message' => $finding['message'] ?? 'Security issue found',
            ];
        }

        $status = $policyResult->passed 
            ? ReviewResultDto::STATUS_APPROVED 
            : ReviewResultDto::STATUS_NEEDS_CHANGES;

        return new ReviewResultDto(
            status: $status,
            issues: $issues,
            suggestions: $suggestions,
            qualityScore: 100 - $policyResult->riskScore, // Convert risk to quality score
            summary: "Policy check completed with risk level: {$policyResult->riskLevel}",
            securityIssues: array_filter($policyResult->securityFindings, fn($f) => ($f['severity'] ?? '') === 'critical' || ($f['severity'] ?? '') === 'high'),
            performanceIssues: [],
            metadata: [
                'risk_level' => $policyResult->riskLevel,
                'risk_score' => $policyResult->riskScore,
                'review_checklist' => $policyResult->reviewChecklist,
                'policy_version' => '1.0',
            ],
        );
    }

    /**
     * Check plan limits.
     */
    private function checkPlanLimits(PlanJson $plan, PolicyCheckResult $result): void
    {
        $limits = $this->policies['limits'] ?? [];

        // Check step count
        $stepCount = count($plan->steps ?? []);
        if ($stepCount > ($limits['max_plan_steps'] ?? 50)) {
            $result->addViolation("Plan has too many steps: {$stepCount} (max: {$limits['max_plan_steps']})");
            $result->retryable = true;
        }

        // Check file count
        $fileCount = count($plan->filesAffected ?? []);
        if ($fileCount > ($limits['max_files_changed'] ?? 20)) {
            $result->addWarning("Plan affects many files: {$fileCount} (max: {$limits['max_files_changed']})");
        }
    }

    /**
     * Check patch limits.
     */
    private function checkPatchLimits(PatchSummaryJson $patch, PolicyCheckResult $result): void
    {
        $limits = $this->policies['limits'] ?? [];

        // Check lines of code
        $totalLoc = $patch->totalLinesChanged();
        if ($totalLoc > ($limits['max_loc_changed'] ?? 500)) {
            $result->addViolation("Too many lines changed: {$totalLoc} (max: {$limits['max_loc_changed']})");
        }

        // Check file count
        $fileCount = count($patch->filesTouched ?? []);
        if ($fileCount > ($limits['max_files_changed'] ?? 20)) {
            $result->addViolation("Too many files changed: {$fileCount} (max: {$limits['max_files_changed']})");
        }
    }

    /**
     * Check if paths are allowed.
     */
    private function checkAllowedPaths(array $paths, PolicyCheckResult $result): void
    {
        $allowedPaths = $this->policies['allowed_paths'] ?? [];
        $includePaths = $allowedPaths['include'] ?? [];
        $excludePaths = $allowedPaths['exclude'] ?? [];

        foreach ($paths as $path) {
            $allowed = false;
            $excluded = false;

            // Check if in include list
            foreach ($includePaths as $pattern) {
                if ($this->pathMatches($path, $pattern)) {
                    $allowed = true;
                    break;
                }
            }

            // Check if in exclude list
            foreach ($excludePaths as $pattern) {
                if ($this->pathMatches($path, $pattern)) {
                    $excluded = true;
                    break;
                }
            }

            if ($excluded || (! empty($includePaths) && ! $allowed)) {
                $result->addViolation("Path not allowed for modification: {$path}");
            }
        }
    }

    /**
     * Run mandatory checks.
     */
    private function runMandatoryChecks(PatchSummaryJson $patch, PolicyCheckResult $result): void
    {
        $checks = $this->policies['mandatory_checks'] ?? [];

        if ($checks['lint'] ?? true) {
            $result->addCheck('lint', true, 'Linting check required');
        }

        if ($checks['typecheck'] ?? true) {
            $result->addCheck('typecheck', true, 'Type checking required');
        }

        if ($checks['unit_tests'] ?? true) {
            $result->addCheck('unit_tests', true, 'Unit tests required');
        }

        if ($checks['integration_tests'] ?? false) {
            $result->addCheck('integration_tests', true, 'Integration tests required');
        }

        if ($checks['security_scan'] ?? true) {
            $result->addCheck('security_scan', true, 'Security scan required');
        }
    }

    /**
     * Run security scans.
     */
    private function runSecurityScans(PatchSummaryJson $patch, PolicyCheckResult $result): void
    {
        $tools = $this->policies['security_tools'] ?? [];

        foreach ($tools as $toolName => $config) {
            if (! ($config['enabled'] ?? false)) {
                continue;
            }

            // Add to required checks
            $result->addCheck("security_{$toolName}", false, "Security scan with {$toolName}");

            // Note: Actual scanning would be done in a separate job
            // This just marks that the scan is required
        }
    }

    /**
     * Calculate risk score for a plan.
     */
    private function calculatePlanRiskScore(PlanJson $plan): int
    {
        $score = 0;
        $weights = $this->policies['risk_scoring']['weights'] ?? [];

        // Check for risk factors in steps
        foreach ($plan->steps ?? [] as $step) {
            foreach ($step->riskFactors ?? [] as $factor) {
                $score += $weights[$factor] ?? 5;
            }
        }

        // Add risk based on scope
        $fileCount = count($plan->filesAffected ?? []);
        if ($fileCount > 10) {
            $score += $weights['large_changeset'] ?? 10;
        }

        // Cap at 100
        return min($score, 100);
    }

    /**
     * Calculate risk score for a patch.
     */
    private function calculatePatchRiskScore(PatchSummaryJson $patch): int
    {
        $score = $patch->riskScore; // Start with the provided risk score
        $weights = $this->policies['risk_scoring']['weights'] ?? [];

        // Add risk based on size
        $totalLoc = $patch->totalLinesChanged();
        if ($totalLoc > 300) {
            $score += $weights['large_changeset'] ?? 10;
        }

        // Add risk if breaking changes
        if ($patch->breakingChanges) {
            $score += $weights['api_breaking_change'] ?? 25;
        }

        // Add risk if migration required
        if ($patch->requiresMigration) {
            $score += $weights['database_migration'] ?? 30;
        }

        // Add risk if low test coverage
        if ($patch->testCoverage !== null && $patch->testCoverage < 70) {
            $score += $weights['insufficient_test_coverage'] ?? 20;
        }

        // Cap at 100
        return min($score, 100);
    }

    /**
     * Get risk level from score.
     */
    private function getRiskLevel(int $score): string
    {
        $thresholds = $this->policies['risk_scoring']['thresholds'] ?? [];

        if ($score >= ($thresholds['critical'] ?? 80)) {
            return 'critical';
        }
        if ($score >= ($thresholds['high'] ?? 60)) {
            return 'high';
        }
        if ($score >= ($thresholds['medium'] ?? 40)) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Generate review checklist based on risk level.
     *
     * @return array<string>
     */
    private function generateReviewChecklist(PatchSummaryJson $patch, string $riskLevel): array
    {
        $checklist = [
            '✓ Code follows project style guidelines',
            '✓ Tests pass locally',
            '✓ No hardcoded secrets or credentials',
            '✓ Error handling is appropriate',
            '✓ Documentation updated if needed',
        ];

        // Add risk-specific checks
        $requirements = $this->policies['review_requirements'][$riskLevel] ?? [];

        if ($requirements['require_senior'] ?? false) {
            $checklist[] = '⚠️ Senior developer review required';
        }

        if ($requirements['require_security_review'] ?? false) {
            $checklist[] = '⚠️ Security team review required';
        }

        if ($requirements['min_reviewers'] ?? 1 > 1) {
            $checklist[] = "⚠️ Minimum {$requirements['min_reviewers']} reviewers required";
        }

        // Add patch-specific checks
        if (count($patch->filesTouched ?? []) > 10) {
            $checklist[] = '⚠️ Large changeset - extra careful review needed';
        }

        if ($patch->requiresMigration) {
            $checklist[] = '⚠️ Database migration - verify rollback procedure';
        }

        if ($patch->breakingChanges) {
            $checklist[] = '⚠️ Breaking changes - check backward compatibility';
        }

        return $checklist;
    }

    /**
     * Check if a path matches a pattern.
     */
    private function pathMatches(string $path, string $pattern): bool
    {
        // Simple pattern matching (can be enhanced)
        $pattern = str_replace('**', '.*', $pattern);
        $pattern = str_replace('*', '[^/]*', $pattern);
        $pattern = '/^'.str_replace('/', '\\/', $pattern).'$/';

        return preg_match($pattern, $path) === 1;
    }

    /**
     * Load security policies from YAML.
     */
    private function loadSecurityPolicies(): void
    {
        $policyFile = storage_path('policies/security.yaml');

        if (File::exists($policyFile)) {
            try {
                $this->securityPolicies = Yaml::parseFile($policyFile);
            } catch (\Exception $e) {
                Log::error('Failed to load security policies', ['error' => $e->getMessage()]);
                $this->securityPolicies = [];
            }
        } else {
            $this->securityPolicies = [];
        }
    }
}

/**
 * Policy check result data class.
 */
class PolicyCheckResult
{
    public bool $passed = true;

    public array $violations = [];

    public array $warnings = [];

    public array $requiredChecks = [];

    public array $securityFindings = [];

    public array $reviewChecklist = [];

    public int $riskScore = 0;

    public string $riskLevel = 'low';

    public bool $retryable = false;

    public ?string $retryReason = null;

    /**
     * Add a policy violation.
     */
    public function addViolation(string $violation): void
    {
        $this->violations[] = $violation;
        $this->passed = false;
    }

    /**
     * Add a warning.
     */
    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Add a required check.
     */
    public function addCheck(string $name, bool $mandatory, string $description): void
    {
        $this->requiredChecks[] = [
            'name' => $name,
            'mandatory' => $mandatory,
            'description' => $description,
        ];
    }

    /**
     * Add a security finding.
     */
    public function addSecurityFinding(string $tool, string $message, string $severity = 'medium'): void
    {
        $this->securityFindings[] = [
            'tool' => $tool,
            'message' => $message,
            'severity' => $severity,
        ];

        if ($severity === 'critical' || $severity === 'high') {
            $this->passed = false;
        }
    }

    /**
     * Convert to array for logging/API responses.
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'risk_score' => $this->riskScore,
            'risk_level' => $this->riskLevel,
            'violations' => $this->violations,
            'warnings' => $this->warnings,
            'required_checks' => $this->requiredChecks,
            'security_findings' => $this->securityFindings,
            'review_checklist' => $this->reviewChecklist,
            'retryable' => $this->retryable,
            'retry_reason' => $this->retryReason,
        ];
    }
}
