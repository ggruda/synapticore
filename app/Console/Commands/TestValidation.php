<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\PatchSummaryJson;
use App\DTO\PlanJson;
use App\Services\Validation\PolicyEnforcer;
use App\Services\Validation\SchemaValidator;
use Illuminate\Console\Command;

/**
 * Test command for schema validation and policy enforcement.
 */
class TestValidation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'validation:test 
                            {--plan : Test plan validation}
                            {--patch : Test patch validation}
                            {--invalid : Test with invalid data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test JSON schema validation and policy enforcement';

    /**
     * Execute the console command.
     */
    public function handle(
        SchemaValidator $validator,
        PolicyEnforcer $enforcer,
    ): int {
        $this->info('🧪 Testing Validation & Policy Enforcement');
        $this->newLine();

        if ($this->option('plan')) {
            $this->testPlanValidation($validator, $enforcer);
        }

        if ($this->option('patch')) {
            $this->testPatchValidation($validator, $enforcer);
        }

        return Command::SUCCESS;
    }

    /**
     * Test plan validation.
     */
    private function testPlanValidation(SchemaValidator $validator, PolicyEnforcer $enforcer): void
    {
        $this->info('📋 Testing Plan Validation...');

        // Create test plan data
        $planData = $this->createTestPlanData($this->option('invalid'));

        try {
            // Validate against schema
            $this->info('Validating against plan.v1.json schema...');
            $result = $validator->validatePlan($planData);

            if ($result->isValid) {
                $this->info('✅ Schema validation passed!');
            } else {
                $this->error('❌ Schema validation failed!');
                foreach ($result->errors as $error) {
                    $this->line("  - {$error}");
                }
            }

            if ($result->hasWarnings()) {
                $this->warn('⚠️ Warnings:');
                foreach ($result->warnings as $warning) {
                    $this->line("  - {$warning}");
                }
            }

            // Check policy compliance
            $this->newLine();
            $this->info('Checking policy compliance...');

            $plan = new PlanJson(
                steps: $planData['steps'] ?? [],
                testStrategy: $planData['test_strategy'] ?? '',
                risk: $planData['risk_level'] ?? 'medium',
                estimatedHours: $planData['estimated_hours'] ?? 0,
                filesAffected: $planData['files_affected'] ?? [],
                summary: $planData['summary'] ?? '',
            );

            $policyResult = $enforcer->checkPlanCompliance($plan);

            if ($policyResult->passed) {
                $this->info('✅ Policy compliance check passed!');
            } else {
                $this->error('❌ Policy violations found!');
                foreach ($policyResult->violations as $violation) {
                    $this->line("  - {$violation}");
                }
            }

            $this->info("Risk Score: {$policyResult->riskScore}");
            $this->info("Risk Level: {$policyResult->riskLevel}");

            if ($policyResult->retryable) {
                $this->warn("Retryable: {$policyResult->retryReason}");
            }

        } catch (\Exception $e) {
            $this->error('Exception: '.$e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Test patch validation.
     */
    private function testPatchValidation(SchemaValidator $validator, PolicyEnforcer $enforcer): void
    {
        $this->info('🔧 Testing Patch Validation...');

        // Create test patch data
        $patchData = $this->createTestPatchData($this->option('invalid'));

        try {
            // Validate against schema
            $this->info('Validating against patch.v1.json schema...');
            $result = $validator->validatePatch($patchData);

            if ($result->isValid) {
                $this->info('✅ Schema validation passed!');
            } else {
                $this->error('❌ Schema validation failed!');
                foreach ($result->errors as $error) {
                    $this->line("  - {$error}");
                }
            }

            if ($result->hasWarnings()) {
                $this->warn('⚠️ Warnings:');
                foreach ($result->warnings as $warning) {
                    $this->line("  - {$warning}");
                }
            }

            // Check policy compliance
            $this->newLine();
            $this->info('Checking policy compliance...');

            $patch = new PatchSummaryJson(
                filesTouched: array_map(fn($f) => $f['path'], $patchData['files_touched'] ?? []),
                diffStats: [
                    'additions' => $patchData['statistics']['total_lines_added'] ?? 0,
                    'deletions' => $patchData['statistics']['total_lines_removed'] ?? 0,
                ],
                riskScore: $patchData['risk']['score'] ?? 0,
                summary: $patchData['summary'] ?? '',
                breakingChanges: in_array('api_breaking_change', array_column($patchData['risk']['factors'] ?? [], 'type')),
                requiresMigration: in_array('database_migration', array_column($patchData['risk']['factors'] ?? [], 'type')),
                testCoverage: $patchData['test_strategy']['coverage']['after'] ?? null,
            );

            $policyResult = $enforcer->checkPatchCompliance($patch);

            if ($policyResult->passed) {
                $this->info('✅ Policy compliance check passed!');
            } else {
                $this->error('❌ Policy violations found!');
                foreach ($policyResult->violations as $violation) {
                    $this->line("  - {$violation}");
                }
            }

            $this->info("Risk Score: {$policyResult->riskScore}");
            $this->info("Risk Level: {$policyResult->riskLevel}");

            // Show review checklist
            if (! empty($policyResult->reviewChecklist)) {
                $this->newLine();
                $this->info('Review Checklist:');
                foreach ($policyResult->reviewChecklist as $item) {
                    $this->line("  {$item}");
                }
            }

            // Generate review result
            $this->newLine();
            $this->info('Generating review result...');
            $reviewResult = $enforcer->generateReviewResult($patch, $policyResult);

            $this->info("Review Status: {$reviewResult->status}");
            $this->info("Quality Score: {$reviewResult->qualityScore}/100");
            $this->info('Review Approved: '.($reviewResult->isApproved() ? 'Yes' : 'No'));

            if (! empty($reviewResult->issues)) {
                $this->error('Issues:');
                foreach ($reviewResult->issues as $issue) {
                    $this->line("  [{$issue['severity']}] {$issue['message']}");
                }
            }

        } catch (\Exception $e) {
            $this->error('Exception: '.$e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Create test plan data.
     */
    private function createTestPlanData(bool $invalid = false): array
    {
        if ($invalid) {
            // Invalid data that should fail validation
            return [
                'version' => '2.0', // Wrong version
                'ticket_id' => 'invalid-id', // Invalid format
                'summary' => 'Short', // Too short
                'estimated_hours' => 100, // Too high
                'risk_level' => 'unknown', // Invalid enum
                'test_strategy' => '', // Empty
                'steps' => [], // Empty array
                'files_affected' => [],
            ];
        }

        // Valid plan data
        return [
            'version' => '1.0',
            'ticket_id' => 'JIRA-123',
            'summary' => 'Implement user authentication feature with OAuth2 support',
            'estimated_hours' => 8.5,
            'risk_level' => 'medium',
            'test_strategy' => 'Unit tests for auth logic, integration tests for OAuth flow, e2e tests for login',
            'steps' => [
                [
                    'id' => 'step_1',
                    'intent' => 'add',
                    'targets' => [
                        [
                            'path' => 'app/Services/AuthService.php',
                            'type' => 'file',
                        ],
                    ],
                    'rationale' => 'Create authentication service to handle OAuth2 flow',
                    'acceptance' => [
                        'Service handles OAuth2 authentication',
                        'Tokens are securely stored',
                        'Refresh token logic implemented',
                    ],
                    'estimated_minutes' => 120,
                    'risk_factors' => ['security_sensitive', 'authentication_change'],
                ],
                [
                    'id' => 'step_2',
                    'intent' => 'modify',
                    'targets' => [
                        [
                            'path' => 'app/Http/Controllers/AuthController.php',
                            'type' => 'class',
                            'line_range' => ['start' => 50, 'end' => 150],
                        ],
                    ],
                    'rationale' => 'Update auth controller to use new OAuth2 service',
                    'acceptance' => [
                        'Controller uses new auth service',
                        'Proper error handling added',
                    ],
                    'estimated_minutes' => 60,
                    'dependencies' => ['step_1'],
                ],
                [
                    'id' => 'step_3',
                    'intent' => 'add_test',
                    'targets' => [
                        [
                            'path' => 'tests/Feature/AuthenticationTest.php',
                            'type' => 'test',
                        ],
                    ],
                    'rationale' => 'Add comprehensive tests for OAuth2 authentication',
                    'acceptance' => [
                        'Tests cover happy path',
                        'Tests cover error scenarios',
                        'Tests verify token handling',
                    ],
                    'estimated_minutes' => 90,
                    'dependencies' => ['step_1', 'step_2'],
                ],
            ],
            'files_affected' => [
                'app/Services/AuthService.php',
                'app/Http/Controllers/AuthController.php',
                'tests/Feature/AuthenticationTest.php',
                'config/auth.php',
                'routes/api.php',
            ],
            'prerequisites' => [
                'dependencies' => ['league/oauth2-client'],
                'environment' => ['OAUTH_CLIENT_ID', 'OAUTH_CLIENT_SECRET'],
                'permissions' => ['write:auth', 'read:users'],
            ],
            'rollback_strategy' => 'Revert to previous auth implementation by feature flag',
            'success_metrics' => [
                ['metric' => 'auth_success_rate', 'target' => '>= 99%'],
                ['metric' => 'auth_latency_p95', 'target' => '< 200ms'],
            ],
            'metadata' => [
                'created_at' => now()->toIso8601String(),
                'model' => 'gpt-4',
                'confidence' => 0.92,
            ],
        ];
    }

    /**
     * Create test patch data.
     */
    private function createTestPatchData(bool $invalid = false): array
    {
        if ($invalid) {
            // Invalid data that should trigger policy violations
            return [
                'version' => '1.0',
                'ticket_id' => 'JIRA-456',
                'summary' => 'Major refactoring with many changes',
                'files_touched' => array_map(fn ($i) => [
                    'path' => "app/file_{$i}.php",
                    'change_type' => 'modified',
                    'lines_added' => 100,
                    'lines_removed' => 50,
                ], range(1, 30)), // Too many files
                'risk' => [
                    'level' => 'critical',
                    'score' => 85,
                    'factors' => [
                        ['type' => 'database_migration', 'severity' => 'high', 'description' => 'Schema changes'],
                        ['type' => 'api_breaking_change', 'severity' => 'critical', 'description' => 'API contract changed'],
                    ],
                ],
                'test_strategy' => [
                    'approach' => 'Manual testing only',
                    'coverage' => ['before' => 80, 'after' => 65, 'delta' => -15], // Coverage decreased
                    'tests_added' => 0, // No tests added
                    'tests_modified' => 0,
                ],
                'statistics' => [
                    'total_files' => 30,
                    'total_lines_added' => 3000, // Too many lines
                    'total_lines_removed' => 1500,
                    'net_lines_changed' => 1500,
                ],
            ];
        }

        // Valid patch data
        return [
            'version' => '1.0',
            'ticket_id' => 'JIRA-456',
            'summary' => 'Implement OAuth2 authentication service with comprehensive tests',
            'files_touched' => [
                [
                    'path' => 'app/Services/AuthService.php',
                    'change_type' => 'added',
                    'lines_added' => 150,
                    'lines_removed' => 0,
                    'changes' => [
                        [
                            'type' => 'class_added',
                            'description' => 'Added AuthService class for OAuth2',
                        ],
                        [
                            'type' => 'method_added',
                            'description' => 'Added authenticate() method',
                        ],
                    ],
                ],
                [
                    'path' => 'app/Http/Controllers/AuthController.php',
                    'change_type' => 'modified',
                    'lines_added' => 45,
                    'lines_removed' => 20,
                    'changes' => [
                        [
                            'type' => 'method_modified',
                            'description' => 'Updated login() to use OAuth2',
                            'line_range' => ['start' => 50, 'end' => 95],
                        ],
                    ],
                ],
                [
                    'path' => 'tests/Feature/AuthenticationTest.php',
                    'change_type' => 'added',
                    'lines_added' => 200,
                    'lines_removed' => 0,
                    'changes' => [
                        [
                            'type' => 'test_added',
                            'description' => 'Added OAuth2 authentication tests',
                        ],
                    ],
                ],
            ],
            'risk' => [
                'level' => 'medium',
                'score' => 45,
                'factors' => [
                    [
                        'type' => 'authentication_change',
                        'severity' => 'medium',
                        'description' => 'Changes to authentication flow',
                        'mitigation' => 'Feature flag for gradual rollout',
                    ],
                    [
                        'type' => 'external_dependency_change',
                        'severity' => 'low',
                        'description' => 'Added OAuth2 client library',
                        'mitigation' => 'Using well-maintained library with good track record',
                    ],
                ],
            ],
            'test_strategy' => [
                'approach' => 'Comprehensive unit and integration testing with mocked OAuth2 providers',
                'coverage' => [
                    'before' => 75.5,
                    'after' => 82.3,
                    'delta' => 6.8,
                ],
                'tests_added' => 15,
                'tests_modified' => 3,
                'test_types' => ['unit', 'integration', 'e2e'],
                'manual_testing_required' => true,
                'manual_test_instructions' => 'Test OAuth2 flow with real providers in staging environment',
            ],
            'statistics' => [
                'total_files' => 5,
                'total_lines_added' => 395,
                'total_lines_removed' => 20,
                'net_lines_changed' => 375,
                'languages' => ['php'],
                'file_types' => [
                    'php' => 4,
                    'yaml' => 1,
                ],
            ],
            'dependencies' => [
                'added' => [
                    ['name' => 'league/oauth2-client', 'version' => '^2.7', 'type' => 'production'],
                ],
                'removed' => [],
                'updated' => [],
            ],
            'security' => [
                'vulnerabilities_found' => 0,
                'vulnerabilities_fixed' => 0,
                'security_checks' => [
                    ['tool' => 'gitleaks', 'status' => 'passed', 'findings' => 0],
                    ['tool' => 'composer_audit', 'status' => 'passed', 'findings' => 0],
                ],
            ],
            'performance' => [
                'impact' => 'neutral',
                'metrics' => [
                    ['metric' => 'auth_latency', 'before' => 150, 'after' => 145, 'change_percentage' => -3.3],
                ],
            ],
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'generation_time_seconds' => 12.5,
                'ai_model' => 'gpt-4',
                'branch' => 'feature/oauth2-auth',
            ],
        ];
    }
}
