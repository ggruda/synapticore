<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket Management Systems
    |--------------------------------------------------------------------------
    */

    'jira' => [
        'url' => env('JIRA_URL'),
        'username' => env('JIRA_USERNAME'),
        'token' => env('JIRA_TOKEN'),
    ],

    'linear' => [
        'api_key' => env('LINEAR_API_KEY'),
    ],

    'azure' => [
        'organization' => env('AZURE_ORGANIZATION'),
        'project' => env('AZURE_PROJECT'),
        'token' => env('AZURE_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Version Control Systems
    |--------------------------------------------------------------------------
    */

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'organization' => env('GITHUB_ORGANIZATION'),
    ],

    'gitlab' => [
        'url' => env('GITLAB_URL', 'https://gitlab.com'),
        'token' => env('GITLAB_TOKEN'),
    ],

    'bitbucket' => [
        'workspace' => env('BITBUCKET_WORKSPACE'),
        'username' => env('BITBUCKET_USERNAME'),
        'app_password' => env('BITBUCKET_APP_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Services
    |--------------------------------------------------------------------------
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'model' => env('OPENAI_MODEL', 'gpt-4-turbo-preview'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-opus-20240229'),
    ],

    'azure_openai' => [
        'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
        'api_key' => env('AZURE_OPENAI_API_KEY'),
        'deployment' => env('AZURE_OPENAI_DEPLOYMENT'),
    ],

    'local_ai' => [
        'endpoint' => env('LOCAL_AI_ENDPOINT', 'http://localhost:11434'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Services
    |--------------------------------------------------------------------------
    */

    'pinecone' => [
        'api_key' => env('PINECONE_API_KEY'),
        'environment' => env('PINECONE_ENVIRONMENT'),
        'index' => env('PINECONE_INDEX'),
    ],

    'weaviate' => [
        'url' => env('WEAVIATE_URL'),
        'api_key' => env('WEAVIATE_API_KEY'),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL'),
        'api_key' => env('QDRANT_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Infrastructure Services
    |--------------------------------------------------------------------------
    */

    'docker' => [
        'socket' => env('DOCKER_SOCKET', '/var/run/docker.sock'),
    ],

    'kubernetes' => [
        'namespace' => env('KUBERNETES_NAMESPACE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    */

    'teams' => [
        'webhook_url' => env('TEAMS_WEBHOOK_URL'),
    ],

    'discord' => [
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Security
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        'secret' => env('WEBHOOK_SECRET'),
    ],

];
