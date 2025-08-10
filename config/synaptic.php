<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ticket Provider
    |--------------------------------------------------------------------------
    |
    | The ticket management system provider to use.
    | Supported: "jira", "linear", "azure"
    |
    */
    'ticket_provider' => env('SYNAPTIC_TICKET_PROVIDER', 'jira'),

    /*
    |--------------------------------------------------------------------------
    | Version Control Provider
    |--------------------------------------------------------------------------
    |
    | The version control system provider to use.
    | Supported: "github", "gitlab", "bitbucket"
    |
    */
    'vcs_provider' => env('SYNAPTIC_VCS_PROVIDER', 'github'),

    /*
    |--------------------------------------------------------------------------
    | AI Service Providers
    |--------------------------------------------------------------------------
    |
    | Configure which AI service to use for each capability.
    | Supported: "openai", "anthropic", "azure-openai", "local"
    |
    */
    'ai' => [
        'planner' => env('SYNAPTIC_AI_PLANNER', 'openai'),
        'implement' => env('SYNAPTIC_AI_IMPLEMENT', 'openai'),
        'review' => env('SYNAPTIC_AI_REVIEW', 'openai'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Provider
    |--------------------------------------------------------------------------
    |
    | The vector embedding provider for semantic search.
    | Supported: "pgvector", "pinecone", "weaviate", "qdrant"
    |
    */
    'embeddings' => env('SYNAPTIC_EMBEDDINGS', 'pgvector'),

    /*
    |--------------------------------------------------------------------------
    | Command Runner
    |--------------------------------------------------------------------------
    |
    | The command execution provider.
    | Supported: "docker", "local", "kubernetes"
    |
    */
    'runner' => env('SYNAPTIC_RUNNER', 'docker'),

    /*
    |--------------------------------------------------------------------------
    | Notification Channel
    |--------------------------------------------------------------------------
    |
    | The default notification channel.
    | Supported: "mail", "slack", "teams", "discord"
    |
    */
    'notify' => env('SYNAPTIC_NOTIFY', 'mail'),

    /*
    |--------------------------------------------------------------------------
    | Worklog Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how worklogs are pushed to external systems.
    | push_mode: "immediate" pushes logs immediately, "batch" batches them
    |
    */
    'worklog' => [
        'push_mode' => env('SYNAPTIC_WORKLOG_PUSH', 'immediate'), // immediate|batch
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure ticket processing behavior.
    | post_plan_comment: Whether to post the plan as a comment on the ticket
    |
    */
    'tickets' => [
        'post_plan_comment' => env('SYNAPTIC_POST_PLAN_COMMENT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies & Guardrails
    |--------------------------------------------------------------------------
    |
    | Define limits, checks, and security policies for the system.
    |
    */
    'policies' => [
        // Code change limits
        'limits' => [
            'max_loc_changed' => env('SYNAPTIC_MAX_LOC_CHANGED', 500),
            'max_files_changed' => env('SYNAPTIC_MAX_FILES_CHANGED', 20),
            'max_complexity_increase' => env('SYNAPTIC_MAX_COMPLEXITY_INCREASE', 10),
            'min_test_coverage' => env('SYNAPTIC_MIN_TEST_COVERAGE', 70),
            'max_plan_steps' => env('SYNAPTIC_MAX_PLAN_STEPS', 50),
        ],

        // Allowed paths for modifications
        'allowed_paths' => [
            'include' => explode(',', env('SYNAPTIC_ALLOWED_PATHS_INCLUDE', 'app/,src/,lib/,tests/')),
            'exclude' => explode(',', env('SYNAPTIC_ALLOWED_PATHS_EXCLUDE', 'vendor/,node_modules/,.git/,dist/')),
        ],

        // Mandatory checks before PR
        'mandatory_checks' => [
            'lint' => env('SYNAPTIC_CHECK_LINT', true),
            'typecheck' => env('SYNAPTIC_CHECK_TYPECHECK', true),
            'unit_tests' => env('SYNAPTIC_CHECK_UNIT_TESTS', true),
            'integration_tests' => env('SYNAPTIC_CHECK_INTEGRATION_TESTS', false),
            'security_scan' => env('SYNAPTIC_CHECK_SECURITY', true),
        ],

        // Security tools configuration
        'security_tools' => [
            'gitleaks' => [
                'enabled' => env('SYNAPTIC_GITLEAKS_ENABLED', true),
                'config_path' => env('SYNAPTIC_GITLEAKS_CONFIG', '.gitleaks.toml'),
            ],
            'trivy' => [
                'enabled' => env('SYNAPTIC_TRIVY_ENABLED', true),
                'severity' => env('SYNAPTIC_TRIVY_SEVERITY', 'CRITICAL,HIGH'),
            ],
            'bandit' => [
                'enabled' => env('SYNAPTIC_BANDIT_ENABLED', true),
                'severity' => env('SYNAPTIC_BANDIT_SEVERITY', 'medium'),
            ],
            'npm_audit' => [
                'enabled' => env('SYNAPTIC_NPM_AUDIT_ENABLED', true),
                'level' => env('SYNAPTIC_NPM_AUDIT_LEVEL', 'moderate'),
            ],
            'composer_audit' => [
                'enabled' => env('SYNAPTIC_COMPOSER_AUDIT_ENABLED', true),
            ],
        ],

        // Risk scoring weights
        'risk_scoring' => [
            'weights' => [
                'database_migration' => 30,
                'api_breaking_change' => 25,
                'security_vulnerability' => 40,
                'performance_degradation' => 15,
                'data_loss_potential' => 35,
                'external_dependency_change' => 10,
                'insufficient_test_coverage' => 20,
                'complex_logic_change' => 15,
                'configuration_change' => 10,
                'authentication_change' => 30,
                'authorization_change' => 30,
                'critical_path_modification' => 25,
                'backward_compatibility' => 20,
                'large_changeset' => 10,
            ],
            'thresholds' => [
                'low' => 20,
                'medium' => 40,
                'high' => 60,
                'critical' => 80,
            ],
        ],

        // Review requirements based on risk
        'review_requirements' => [
            'low' => [
                'min_reviewers' => 1,
                'require_senior' => false,
                'require_security_review' => false,
            ],
            'medium' => [
                'min_reviewers' => 1,
                'require_senior' => false,
                'require_security_review' => false,
            ],
            'high' => [
                'min_reviewers' => 2,
                'require_senior' => true,
                'require_security_review' => false,
            ],
            'critical' => [
                'min_reviewers' => 2,
                'require_senior' => true,
                'require_security_review' => true,
            ],
        ],

        // Retry policies
        'retries' => [
            'max_validation_retries' => env('SYNAPTIC_MAX_VALIDATION_RETRIES', 3),
            'max_implementation_retries' => env('SYNAPTIC_MAX_IMPLEMENTATION_RETRIES', 2),
            'max_test_retries' => env('SYNAPTIC_MAX_TEST_RETRIES', 3),
        ],
    ],
];
