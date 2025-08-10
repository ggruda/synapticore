<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Data;

/**
 * Repository profile containing detected tools and commands.
 */
final readonly class RepoProfileJson extends Data
{
    /**
     * @param  array<string, string>  $commands  Available commands (lint, format, test, etc.)
     * @param  array<string, mixed>  $dependencies  Detected dependencies and versions
     * @param  array<string>  $languages  Detected programming languages
     * @param  array<string, mixed>  $frameworks  Detected frameworks and versions
     * @param  array<string>  $tools  Available tools (composer, npm, etc.)
     * @param  array<string>  $manifests  Found manifest files
     * @param  array<string, mixed>  $metadata  Additional metadata
     */
    public function __construct(
        public array $commands,
        public array $dependencies,
        public array $languages,
        public array $frameworks,
        public array $tools,
        public array $manifests,
        public array $metadata,
    ) {}

    /**
     * Get the primary language.
     */
    public function primaryLanguage(): ?string
    {
        return $this->languages[0] ?? null;
    }

    /**
     * Get the primary framework.
     */
    public function primaryFramework(): ?string
    {
        return array_key_first($this->frameworks);
    }

    /**
     * Check if a specific command is available.
     */
    public function hasCommand(string $command): bool
    {
        return isset($this->commands[$command]);
    }

    /**
     * Get command or fallback.
     */
    public function getCommand(string $command, ?string $default = null): ?string
    {
        return $this->commands[$command] ?? $default;
    }
}
