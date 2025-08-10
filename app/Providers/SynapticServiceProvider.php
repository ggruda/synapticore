<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AiImplementerContract;
use App\Contracts\AiPlannerContract;
use App\Contracts\AiReviewerContract;
use App\Contracts\EmbeddingProviderContract;
use App\Contracts\NotificationChannelContract;
use App\Contracts\RunnerContract;
use App\Contracts\TicketProviderContract;
use App\Contracts\VcsProviderContract;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for binding Synaptic contracts to concrete implementations.
 * Provider selection is driven by configuration values.
 */
class SynapticServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerTicketProvider();
        $this->registerVcsProvider();
        $this->registerAiProviders();
        $this->registerEmbeddingProvider();
        $this->registerRunner();
        $this->registerNotificationChannel();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register the ticket provider based on configuration.
     */
    private function registerTicketProvider(): void
    {
        $this->app->singleton(TicketProviderContract::class, function ($app) {
            $provider = config('synaptic.ticket_provider');

            return match ($provider) {
                'jira' => new \App\Services\Tickets\JiraTicketProvider(
                    config('services.jira.url') ?? 'https://example.atlassian.net',
                    config('services.jira.username') ?? 'user@example.com',
                    config('services.jira.token') ?? 'dummy-token'
                ),
                'linear' => new \App\Services\Tickets\LinearTicketProvider(
                    config('services.linear.api_key') ?? 'dummy-api-key'
                ),
                'azure' => new \App\Services\Tickets\AzureTicketProvider(
                    config('services.azure.organization') ?? 'dummy-org',
                    config('services.azure.project') ?? 'dummy-project',
                    config('services.azure.token') ?? 'dummy-token'
                ),
                default => throw new \InvalidArgumentException("Unknown ticket provider: {$provider}"),
            };
        });
    }

    /**
     * Register the VCS provider based on configuration.
     */
    private function registerVcsProvider(): void
    {
        $this->app->singleton(VcsProviderContract::class, function ($app) {
            $provider = config('synaptic.vcs_provider');

            return match ($provider) {
                'github' => new \App\Services\Vcs\GithubVcsProvider(
                    config('services.github.token') ?? 'dummy-github-token',
                    config('services.github.organization')
                ),
                'gitlab' => new \App\Services\Vcs\GitlabVcsProvider(
                    config('services.gitlab.url') ?? 'https://gitlab.com',
                    config('services.gitlab.token') ?? 'dummy-gitlab-token'
                ),
                'bitbucket' => new \App\Services\Vcs\BitbucketVcsProvider(
                    config('services.bitbucket.workspace') ?? 'dummy-workspace',
                    config('services.bitbucket.username') ?? 'dummy-user',
                    config('services.bitbucket.app_password') ?? 'dummy-password'
                ),
                default => throw new \InvalidArgumentException("Unknown VCS provider: {$provider}"),
            };
        });
    }

    /**
     * Register AI service providers based on configuration.
     */
    private function registerAiProviders(): void
    {
        // AI Planner
        $this->app->singleton(AiPlannerContract::class, function ($app) {
            $provider = config('synaptic.ai.planner');

            return match ($provider) {
                'openai' => new \App\Services\AI\OpenAiPlanner(
                    config('services.openai.api_key') ?? 'dummy-openai-key',
                    config('services.openai.model') ?? 'gpt-4-turbo-preview'
                ),
                'anthropic' => new \App\Services\AI\AnthropicPlanner(
                    config('services.anthropic.api_key') ?? 'dummy-anthropic-key',
                    config('services.anthropic.model') ?? 'claude-3-opus-20240229'
                ),
                'azure-openai' => new \App\Services\AI\AzureOpenAiPlanner(
                    config('services.azure_openai.endpoint') ?? 'https://dummy.openai.azure.com',
                    config('services.azure_openai.api_key') ?? 'dummy-azure-key',
                    config('services.azure_openai.deployment') ?? 'gpt-4'
                ),
                'local' => new \App\Services\AI\LocalAiPlanner(
                    config('services.local_ai.endpoint') ?? 'http://localhost:11434'
                ),
                default => throw new \InvalidArgumentException("Unknown AI planner provider: {$provider}"),
            };
        });

        // AI Implementer
        $this->app->singleton(AiImplementerContract::class, function ($app) {
            $provider = config('synaptic.ai.implement');

            return match ($provider) {
                'openai' => new \App\Services\AI\OpenAiImplementer(
                    config('services.openai.api_key') ?? 'dummy-openai-key',
                    config('services.openai.model') ?? 'gpt-4-turbo-preview'
                ),
                'anthropic' => new \App\Services\AI\AnthropicImplementer(
                    config('services.anthropic.api_key') ?? 'dummy-anthropic-key',
                    config('services.anthropic.model') ?? 'claude-3-opus-20240229'
                ),
                'azure-openai' => new \App\Services\AI\AzureOpenAiImplementer(
                    config('services.azure_openai.endpoint') ?? 'https://dummy.openai.azure.com',
                    config('services.azure_openai.api_key') ?? 'dummy-azure-key',
                    config('services.azure_openai.deployment') ?? 'gpt-4'
                ),
                'local' => new \App\Services\AI\LocalAiImplementer(
                    config('services.local_ai.endpoint') ?? 'http://localhost:11434'
                ),
                default => throw new \InvalidArgumentException("Unknown AI implementer provider: {$provider}"),
            };
        });

        // AI Reviewer
        $this->app->singleton(AiReviewerContract::class, function ($app) {
            $provider = config('synaptic.ai.review');

            return match ($provider) {
                'openai' => new \App\Services\AI\OpenAiReviewer(
                    config('services.openai.api_key') ?? 'dummy-openai-key',
                    config('services.openai.model') ?? 'gpt-4-turbo-preview'
                ),
                'anthropic' => new \App\Services\AI\AnthropicReviewer(
                    config('services.anthropic.api_key') ?? 'dummy-anthropic-key',
                    config('services.anthropic.model') ?? 'claude-3-opus-20240229'
                ),
                'azure-openai' => new \App\Services\AI\AzureOpenAiReviewer(
                    config('services.azure_openai.endpoint') ?? 'https://dummy.openai.azure.com',
                    config('services.azure_openai.api_key') ?? 'dummy-azure-key',
                    config('services.azure_openai.deployment') ?? 'gpt-4'
                ),
                'local' => new \App\Services\AI\LocalAiReviewer(
                    config('services.local_ai.endpoint') ?? 'http://localhost:11434'
                ),
                default => throw new \InvalidArgumentException("Unknown AI reviewer provider: {$provider}"),
            };
        });
    }

    /**
     * Register the embedding provider based on configuration.
     */
    private function registerEmbeddingProvider(): void
    {
        $this->app->singleton(EmbeddingProviderContract::class, function ($app) {
            $provider = config('synaptic.embeddings');

            return match ($provider) {
                'pgvector' => new \App\Services\Embeddings\PgvectorEmbeddingProvider(
                    $app->make(\Illuminate\Database\ConnectionInterface::class),
                    config('services.openai.api_key') ?? 'dummy-openai-key' // For generating embeddings
                ),
                'pinecone' => new \App\Services\Embeddings\PineconeEmbeddingProvider(
                    config('services.pinecone.api_key') ?? 'dummy-pinecone-key',
                    config('services.pinecone.environment') ?? 'us-west1-gcp',
                    config('services.pinecone.index') ?? 'default-index'
                ),
                'weaviate' => new \App\Services\Embeddings\WeaviateEmbeddingProvider(
                    config('services.weaviate.url') ?? 'http://localhost:8080',
                    config('services.weaviate.api_key') ?? 'dummy-weaviate-key'
                ),
                'qdrant' => new \App\Services\Embeddings\QdrantEmbeddingProvider(
                    config('services.qdrant.url') ?? 'http://localhost:6333',
                    config('services.qdrant.api_key') ?? 'dummy-qdrant-key'
                ),
                default => throw new \InvalidArgumentException("Unknown embedding provider: {$provider}"),
            };
        });
    }

    /**
     * Register the command runner based on configuration.
     */
    private function registerRunner(): void
    {
        $this->app->singleton(RunnerContract::class, function ($app) {
            $runner = config('synaptic.runner');

            return match ($runner) {
                'docker' => new \App\Services\Runner\DockerRunner(
                    config('services.docker.socket') ?? '/var/run/docker.sock'
                ),
                'local' => new \App\Services\Runner\LocalRunner,
                'kubernetes' => new \App\Services\Runner\KubernetesRunner(
                    config('services.kubernetes.namespace') ?? 'default'
                ),
                default => throw new \InvalidArgumentException("Unknown runner: {$runner}"),
            };
        });
    }

    /**
     * Register the notification channel based on configuration.
     */
    private function registerNotificationChannel(): void
    {
        $this->app->singleton(NotificationChannelContract::class, function ($app) {
            $channel = config('synaptic.notify');

            return match ($channel) {
                'mail' => new \App\Services\Notify\MailChannel,
                'slack' => new \App\Services\Notify\SlackChannel(
                    config('services.slack.webhook_url') ?? 'https://hooks.slack.com/dummy'
                ),
                'teams' => new \App\Services\Notify\TeamsChannel(
                    config('services.teams.webhook_url') ?? 'https://outlook.office.com/webhook/dummy'
                ),
                'discord' => new \App\Services\Notify\DiscordChannel(
                    config('services.discord.webhook_url') ?? 'https://discord.com/api/webhooks/dummy'
                ),
                default => throw new \InvalidArgumentException("Unknown notification channel: {$channel}"),
            };
        });
    }
}
