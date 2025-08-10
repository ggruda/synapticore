<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for AI implementer input.
 */
#[Immutable]
final class ImplementInputDto extends Data
{
    /**
     * @param  array  $step  The plan step to implement
     * @param  array  $context  Implementation context (files, dependencies, etc.)
     * @param  string  $workspace  Workspace path
     */
    public function __construct(
        public readonly array $step,
        public readonly array $context,
        public readonly string $workspace,
    ) {}

    /**
     * Get step intent.
     */
    public function getIntent(): string
    {
        return $this->step['intent'] ?? 'modify';
    }

    /**
     * Get target files.
     *
     * @return array<string>
     */
    public function getTargetFiles(): array
    {
        $files = [];

        foreach ($this->step['targets'] ?? [] as $target) {
            if (isset($target['path'])) {
                $files[] = $target['path'];
            }
        }

        return $files;
    }

    /**
     * Get step acceptance criteria.
     *
     * @return array<string>
     */
    public function getAcceptanceCriteria(): array
    {
        return $this->step['acceptance'] ?? [];
    }
}
