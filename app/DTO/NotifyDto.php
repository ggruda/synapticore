<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for notifications.
 */
#[Immutable]
final class NotifyDto extends Data
{
    public const LEVEL_INFO = 'info';

    public const LEVEL_SUCCESS = 'success';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_CRITICAL = 'critical';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SLACK = 'slack';

    public const CHANNEL_TEAMS = 'teams';

    public const CHANNEL_WEBHOOK = 'webhook';

    public const CHANNEL_SMS = 'sms';

    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly string $level = self::LEVEL_INFO,
        public readonly array $channels = [],
        public readonly array $recipients = [],
        public readonly array $data = [],
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionText = null,
        public readonly array $attachments = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Check if this is a critical notification.
     */
    public function isCritical(): bool
    {
        return $this->level === self::LEVEL_CRITICAL || $this->level === self::LEVEL_ERROR;
    }

    /**
     * Get channels or default to all available.
     */
    public function getChannels(): array
    {
        return empty($this->channels) ? [self::CHANNEL_EMAIL] : $this->channels;
    }
}
