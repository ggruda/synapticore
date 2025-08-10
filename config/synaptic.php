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
];
