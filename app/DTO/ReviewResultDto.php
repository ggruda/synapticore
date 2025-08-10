<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for AI review results.
 */
#[Immutable]
final class ReviewResultDto extends Data
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_NEEDS_CHANGES = 'needs_changes';

    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        public readonly string $status,
        public readonly array $issues = [],
        public readonly array $suggestions = [],
        public readonly array $approvals = [],
        public readonly int $qualityScore = 0,
        public readonly string $summary = '',
        public readonly array $securityIssues = [],
        public readonly array $performanceIssues = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Check if the review is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if changes are required.
     */
    public function needsChanges(): bool
    {
        return $this->status === self::STATUS_NEEDS_CHANGES;
    }

    /**
     * Get total issue count.
     */
    public function issueCount(): int
    {
        return count($this->issues) + count($this->securityIssues) + count($this->performanceIssues);
    }
}
