<?php

declare(strict_types=1);

namespace App\Services\Notify;

use App\Contracts\NotificationChannelContract;
use App\DTO\NotifyDto;
use App\Exceptions\NotImplementedException;

/**
 * Discord skeleton implementation of the notification channel contract.
 */
class DiscordChannel implements NotificationChannelContract
{
    public function __construct(
        private readonly string $webhookUrl,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function notify(NotifyDto $dto): void
    {
        throw new NotImplementedException('DiscordChannel::notify() not yet implemented');
    }
}
