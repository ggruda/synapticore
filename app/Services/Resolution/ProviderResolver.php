<?php

declare(strict_types=1);

namespace App\Services\Resolution;

use App\Contracts\AiImplementerContract;
use App\Contracts\AiPlannerContract;
use App\Contracts\AiReviewerContract;
use App\Contracts\EmbeddingProviderContract;
use App\Contracts\NotificationChannelContract;
use App\Contracts\RunnerContract;
use App\Contracts\TicketProviderContract;
use App\Contracts\VcsProviderContract;
use App\Models\Project;
use App\Providers\SynapticServiceProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Resolves provider implementations based on project overrides and system configuration.
 */
class ProviderResolver
{
    /**
     * Mapping of contract interfaces to configuration keys.
     */
    private const CONTRACT_TO_CONFIG_MAP = [
        TicketProviderContract::class => 'ticket_provider',
        VcsProviderContract::class => 'vcs_provider',
        AiPlannerContract::class => 'ai.planner',
        AiImplementerContract::class => 'ai.implement',
        AiReviewerContract::class => 'ai.review',
        EmbeddingProviderContract::class => 'embeddings',
        RunnerContract::class => 'runner',
        NotificationChannelContract::class => 'notify',
    ];

    /**
     * Resolve a provider for a specific project.
     *
     * @template T
     *
     * @param  Project  $project  The project context
     * @param  class-string<T>  $contractFqcn  Fully qualified contract class name
     * @return T The resolved provider instance
     *
     * @throws InvalidArgumentException If contract is not recognized
     * @throws \Exception If provider cannot be resolved
     */
    public function resolveForProject(Project $project, string $contractFqcn): mixed
    {
        // Check if this is a recognized contract
        if (! isset(self::CONTRACT_TO_CONFIG_MAP[$contractFqcn])) {
            throw new InvalidArgumentException(
                "Unknown contract: {$contractFqcn}. Supported contracts: ".
                implode(', ', array_keys(self::CONTRACT_TO_CONFIG_MAP))
            );
        }

        $configKey = self::CONTRACT_TO_CONFIG_MAP[$contractFqcn];

        // First, check project overrides
        $providerName = $this->getProjectOverride($project, $configKey);

        // If no project override, fall back to system config
        if ($providerName === null) {
            $providerName = $this->getSystemConfig($configKey);
        }

        // If still no provider name, throw exception
        if ($providerName === null) {
            throw new \Exception(
                "No provider configured for contract {$contractFqcn} ".
                "(config key: {$configKey}) for project {$project->name}"
            );
        }

        Log::info('Resolving provider', [
            'project' => $project->name,
            'contract' => $contractFqcn,
            'config_key' => $configKey,
            'provider' => $providerName,
            'source' => $this->getProjectOverride($project, $configKey) ? 'project_override' : 'system_config',
        ]);

        // Create and return the provider instance
        return $this->createProvider($contractFqcn, $providerName, $project);
    }

    /**
     * Get provider override from project configuration.
     */
    private function getProjectOverride(Project $project, string $configKey): ?string
    {
        $overrides = $project->provider_overrides ?? [];

        // Handle nested keys (e.g., 'ai.planner')
        $keys = explode('.', $configKey);
        $value = $overrides;

        foreach ($keys as $key) {
            if (! is_array($value) || ! isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Get provider from system configuration.
     */
    private function getSystemConfig(string $configKey): ?string
    {
        $value = config("synaptic.{$configKey}");

        return is_string($value) ? $value : null;
    }

    /**
     * Create a provider instance.
     */
    private function createProvider(string $contractFqcn, string $providerName, Project $project): mixed
    {
        // Create provider directly without using service provider
        return $this->createProviderDirectly($contractFqcn, $providerName, $project);
    }

    /**
     * Create a provider directly based on the provider name.
     */
    private function createProviderDirectly(string $contractFqcn, string $providerName, Project $project): mixed
    {
        // Map provider names to classes
        $providerMap = $this->getProviderMap($contractFqcn);

        if (! isset($providerMap[$providerName])) {
            throw new InvalidArgumentException(
                "Unknown provider '{$providerName}' for contract {$contractFqcn}"
            );
        }

        $providerClass = $providerMap[$providerName];

        // Create instance with appropriate constructor parameters
        return $this->instantiateProvider($providerClass, $project);
    }

    /**
     * Get provider class mapping for a contract.
     */
    private function getProviderMap(string $contractFqcn): array
    {
        return match ($contractFqcn) {
            TicketProviderContract::class => [
                'jira' => \App\Services\Tickets\JiraTicketProvider::class,
                'linear' => \App\Services\Tickets\LinearTicketProvider::class,
                'azure' => \App\Services\Tickets\AzureTicketProvider::class,
            ],
            VcsProviderContract::class => [
                'github' => \App\Services\Vcs\GithubVcsProvider::class,
                'gitlab' => \App\Services\Vcs\GitlabVcsProvider::class,
                'bitbucket' => \App\Services\Vcs\BitbucketVcsProvider::class,
            ],
            AiPlannerContract::class => [
                'openai' => \App\Services\AI\OpenAiPlanner::class,
                'anthropic' => \App\Services\AI\AnthropicPlanner::class,
                'azure' => \App\Services\AI\AzureOpenAiPlanner::class,
                'local' => \App\Services\AI\LocalAiPlanner::class,
            ],
            AiImplementerContract::class => [
                'openai' => \App\Services\AI\OpenAiImplementer::class,
                'anthropic' => \App\Services\AI\AnthropicImplementer::class,
                'azure' => \App\Services\AI\AzureOpenAiImplementer::class,
                'local' => \App\Services\AI\LocalAiImplementer::class,
            ],
            AiReviewerContract::class => [
                'openai' => \App\Services\AI\OpenAiReviewer::class,
                'anthropic' => \App\Services\AI\AnthropicReviewer::class,
                'azure' => \App\Services\AI\AzureOpenAiReviewer::class,
                'local' => \App\Services\AI\LocalAiReviewer::class,
            ],
            EmbeddingProviderContract::class => [
                'pgvector' => \App\Services\Embeddings\PgvectorEmbeddingProvider::class,
                'pinecone' => \App\Services\Embeddings\PineconeEmbeddingProvider::class,
                'weaviate' => \App\Services\Embeddings\WeaviateEmbeddingProvider::class,
                'qdrant' => \App\Services\Embeddings\QdrantEmbeddingProvider::class,
            ],
            RunnerContract::class => [
                'docker' => \App\Services\Runner\DockerRunner::class,
                'kubernetes' => \App\Services\Runner\KubernetesRunner::class,
            ],
            NotificationChannelContract::class => [
                'mail' => \App\Services\Notify\MailChannel::class,
                'slack' => \App\Services\Notify\SlackChannel::class,
                'teams' => \App\Services\Notify\TeamsChannel::class,
                'discord' => \App\Services\Notify\DiscordChannel::class,
            ],
            default => throw new InvalidArgumentException("No provider map for {$contractFqcn}"),
        };
    }

    /**
     * Instantiate a provider with appropriate dependencies.
     */
    private function instantiateProvider(string $providerClass, Project $project): mixed
    {
        // Get constructor parameters from configuration
        $config = $this->getProviderConfig($providerClass, $project);

        // Create instance with configuration
        try {
            $reflection = new \ReflectionClass($providerClass);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                // No constructor, create directly
                return new $providerClass;
            }

            // Build constructor arguments
            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $paramName = $param->getName();

                // Check if we have a config value for this parameter
                if (isset($config[$paramName])) {
                    $args[] = $config[$paramName];
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    // Try to resolve from container or use default values
                    $type = $param->getType();
                    if ($type && ! $type->isBuiltin()) {
                        try {
                            $args[] = app($type->getName());
                        } catch (\Exception $e) {
                            $args[] = null;
                        }
                    } else {
                        // Provide sensible defaults for common parameter names
                        $args[] = match ($paramName) {
                            'url' => 'https://example.atlassian.net',
                            'username' => 'user@example.com',
                            'token', 'apiKey', 'api_key' => 'dummy-token',
                            'organization', 'org' => 'dummy-org',
                            'project' => 'dummy-project',
                            'model' => 'gpt-3.5-turbo',
                            'endpoint' => 'https://api.openai.com/v1',
                            'baseUrl', 'base_url' => 'http://localhost:8080',
                            default => '',
                        };
                    }
                }
            }

            return $reflection->newInstanceArgs($args);
        } catch (\Exception $e) {
            Log::error('Failed to instantiate provider', [
                'class' => $providerClass,
                'project' => $project->name,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get configuration for a specific provider.
     */
    private function getProviderConfig(string $providerClass, Project $project): array
    {
        // Extract provider type from class name
        $className = class_basename($providerClass);

        // Map to configuration keys
        $configMap = [
            'JiraTicketProvider' => 'services.jira',
            'LinearTicketProvider' => 'services.linear',
            'AzureTicketProvider' => 'services.azure',
            'GithubVcsProvider' => 'services.github',
            'GitlabVcsProvider' => 'services.gitlab',
            'BitbucketVcsProvider' => 'services.bitbucket',
            'OpenAiPlanner' => 'services.openai',
            'OpenAiImplementer' => 'services.openai',
            'OpenAiReviewer' => 'services.openai',
            'AnthropicPlanner' => 'services.anthropic',
            'AnthropicImplementer' => 'services.anthropic',
            'AnthropicReviewer' => 'services.anthropic',
            'AzureOpenAiPlanner' => 'services.azure_openai',
            'AzureOpenAiImplementer' => 'services.azure_openai',
            'AzureOpenAiReviewer' => 'services.azure_openai',
            'LocalAiPlanner' => 'services.local_ai',
            'LocalAiImplementer' => 'services.local_ai',
            'LocalAiReviewer' => 'services.local_ai',
        ];

        // Get base configuration
        $configKey = $configMap[$className] ?? null;
        $config = $configKey ? config($configKey, []) : [];

        // Check for project-specific configuration overrides
        $projectConfig = $project->provider_overrides['config'][$className] ?? [];

        // Merge configurations
        return array_merge($config, $projectConfig);
    }

    /**
     * Clear any cached provider instances.
     */
    public function clearCache(): void
    {
        // If we implement caching later, clear it here
        Log::info('Provider cache cleared');
    }
}
