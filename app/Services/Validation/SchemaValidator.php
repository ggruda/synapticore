<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Exceptions\ValidationFailedException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * Service for validating JSON data against schemas.
 */
class SchemaValidator
{
    private const SCHEMA_PATH = 'storage/schemas';

    private array $loadedSchemas = [];

    /**
     * Validate data against a schema.
     *
     * @param  mixed  $data  Data to validate (will be converted to object)
     * @param  string  $schemaName  Schema name (e.g., 'plan.v1', 'patch.v1')
     *
     * @throws ValidationFailedException
     */
    public function validate($data, string $schemaName): ValidationResult
    {
        $schema = $this->loadSchema($schemaName);

        // Convert data to object for validation
        $dataObject = json_decode(json_encode($data));

        // Create validator
        $validator = new Validator;

        // Validate
        $validator->validate(
            $dataObject,
            $schema,
            Constraint::CHECK_MODE_COERCE_TYPES
        );

        // Create result
        $result = new ValidationResult(
            isValid: $validator->isValid(),
            errors: $this->formatErrors($validator->getErrors()),
            warnings: [],
            schemaVersion: $schema->version ?? '1.0',
        );

        if (! $result->isValid) {
            Log::warning('Schema validation failed', [
                'schema' => $schemaName,
                'errors' => $result->errors,
            ]);
        }

        return $result;
    }

    /**
     * Validate plan data.
     *
     * @throws ValidationFailedException
     */
    public function validatePlan(array $planData): ValidationResult
    {
        $result = $this->validate($planData, 'plan.v1');

        if (! $result->isValid) {
            throw new ValidationFailedException(
                'Plan validation failed: '.implode('; ', $result->errors)
            );
        }

        // Additional business logic validation
        $this->validatePlanBusinessRules($planData, $result);

        return $result;
    }

    /**
     * Validate patch data.
     *
     * @throws ValidationFailedException
     */
    public function validatePatch(array $patchData): ValidationResult
    {
        $result = $this->validate($patchData, 'patch.v1');

        if (! $result->isValid) {
            throw new ValidationFailedException(
                'Patch validation failed: '.implode('; ', $result->errors)
            );
        }

        // Additional business logic validation
        $this->validatePatchBusinessRules($patchData, $result);

        return $result;
    }

    /**
     * Load schema from file.
     *
     * @throws ValidationFailedException
     */
    private function loadSchema(string $schemaName): object
    {
        if (isset($this->loadedSchemas[$schemaName])) {
            return $this->loadedSchemas[$schemaName];
        }

        $schemaPath = base_path(self::SCHEMA_PATH.'/'.$schemaName.'.json');

        if (! File::exists($schemaPath)) {
            throw new ValidationFailedException("Schema not found: {$schemaName}");
        }

        $schemaContent = File::get($schemaPath);
        $schema = json_decode($schemaContent);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationFailedException(
                "Invalid schema JSON: {$schemaName} - ".json_last_error_msg()
            );
        }

        $this->loadedSchemas[$schemaName] = $schema;

        return $schema;
    }

    /**
     * Format validation errors for readability.
     *
     * @return array<string>
     */
    private function formatErrors(array $errors): array
    {
        $formatted = [];

        foreach ($errors as $error) {
            $property = $error['property'] ?? 'root';
            $message = $error['message'] ?? 'Unknown error';
            $formatted[] = "{$property}: {$message}";
        }

        return $formatted;
    }

    /**
     * Additional business rule validation for plans.
     */
    private function validatePlanBusinessRules(array $planData, ValidationResult $result): void
    {
        $policies = config('synaptic.policies.limits');

        // Check step count
        if (isset($planData['steps']) && count($planData['steps']) > $policies['max_plan_steps']) {
            $result->addError(
                'Plan has too many steps: '.count($planData['steps'])." (max: {$policies['max_plan_steps']})"
            );
        }

        // Check file count
        if (isset($planData['files_affected']) && count($planData['files_affected']) > $policies['max_files_changed']) {
            $result->addWarning(
                'Plan affects many files: '.count($planData['files_affected'])." (recommended max: {$policies['max_files_changed']})"
            );
        }

        // Validate step dependencies
        if (isset($planData['steps'])) {
            $stepIds = array_column($planData['steps'], 'id');
            foreach ($planData['steps'] as $step) {
                if (isset($step['dependencies'])) {
                    foreach ($step['dependencies'] as $depId) {
                        if (! in_array($depId, $stepIds)) {
                            $result->addError("Step {$step['id']} has invalid dependency: {$depId}");
                        }
                    }
                }
            }
        }

        // Check estimated time
        if (isset($planData['estimated_hours']) && $planData['estimated_hours'] > 40) {
            $result->addWarning("Plan estimated time is very high: {$planData['estimated_hours']} hours");
        }

        // Validate risk level consistency
        if (isset($planData['risk_level']) && isset($planData['steps'])) {
            $hasHighRiskFactors = false;
            foreach ($planData['steps'] as $step) {
                if (isset($step['risk_factors'])) {
                    foreach ($step['risk_factors'] as $factor) {
                        if (in_array($factor, ['database_migration', 'api_change', 'security_sensitive', 'data_loss_risk'])) {
                            $hasHighRiskFactors = true;
                            break 2;
                        }
                    }
                }
            }

            if ($hasHighRiskFactors && $planData['risk_level'] === 'low') {
                $result->addWarning('Plan marked as low risk but contains high-risk factors');
            }
        }
    }

    /**
     * Additional business rule validation for patches.
     */
    private function validatePatchBusinessRules(array $patchData, ValidationResult $result): void
    {
        $policies = config('synaptic.policies.limits');

        // Check lines of code changed
        if (isset($patchData['statistics'])) {
            $stats = $patchData['statistics'];
            $totalLoc = ($stats['total_lines_added'] ?? 0) + ($stats['total_lines_removed'] ?? 0);

            if ($totalLoc > $policies['max_loc_changed']) {
                $result->addWarning(
                    "Patch changes too many lines: {$totalLoc} (max: {$policies['max_loc_changed']})"
                );
            }

            if (($stats['total_files'] ?? 0) > $policies['max_files_changed']) {
                $result->addWarning(
                    "Patch affects too many files: {$stats['total_files']} (max: {$policies['max_files_changed']})"
                );
            }
        }

        // Validate test coverage
        if (isset($patchData['test_strategy']['coverage'])) {
            $coverage = $patchData['test_strategy']['coverage'];
            $minCoverage = $policies['min_test_coverage'];

            if (isset($coverage['after']) && $coverage['after'] < $minCoverage) {
                $result->addError("Test coverage below minimum: {$coverage['after']}% (min: {$minCoverage}%)");
            }

            if (isset($coverage['delta']) && $coverage['delta'] < -5) {
                $result->addWarning("Test coverage decreased significantly: {$coverage['delta']}%");
            }
        }

        // Check security scan results
        if (isset($patchData['security']['vulnerabilities_found']) && $patchData['security']['vulnerabilities_found'] > 0) {
            $result->addError("Security vulnerabilities found: {$patchData['security']['vulnerabilities_found']}");
        }

        // Validate risk score consistency
        if (isset($patchData['risk'])) {
            $risk = $patchData['risk'];
            $thresholds = config('synaptic.policies.risk_scoring.thresholds');

            $expectedLevel = 'low';
            foreach ($thresholds as $level => $threshold) {
                if (($risk['score'] ?? 0) >= $threshold) {
                    $expectedLevel = $level;
                }
            }

            if ($risk['level'] !== $expectedLevel) {
                $result->addWarning(
                    "Risk level mismatch: marked as {$risk['level']} but score {$risk['score']} suggests {$expectedLevel}"
                );
            }
        }

        // Check for required tests
        if (isset($patchData['test_strategy'])) {
            $testStrategy = $patchData['test_strategy'];
            if (($testStrategy['tests_added'] ?? 0) === 0 && ($testStrategy['tests_modified'] ?? 0) === 0) {
                $result->addWarning('No tests added or modified in patch');
            }
        }
    }
}

/**
 * Validation result data class.
 */
class ValidationResult
{
    public function __construct(
        public bool $isValid,
        public array $errors,
        public array $warnings,
        public string $schemaVersion,
    ) {}

    /**
     * Add an error to the result.
     */
    public function addError(string $error): void
    {
        $this->errors[] = $error;
        $this->isValid = false;
    }

    /**
     * Add a warning to the result.
     */
    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Check if there are any warnings.
     */
    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    /**
     * Get all issues (errors and warnings).
     *
     * @return array<string>
     */
    public function getAllIssues(): array
    {
        return array_merge(
            array_map(fn ($e) => "[ERROR] {$e}", $this->errors),
            array_map(fn ($w) => "[WARNING] {$w}", $this->warnings)
        );
    }

    /**
     * Convert to array for logging/API responses.
     */
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'schema_version' => $this->schemaVersion,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'error_count' => count($this->errors),
            'warning_count' => count($this->warnings),
        ];
    }
}
