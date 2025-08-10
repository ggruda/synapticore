<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\NotifyDto;

/**
 * Contract for notification channel providers.
 */
interface NotificationChannelContract
{
    /**
     * Send a notification through the channel.
     *
     * @param  NotifyDto  $dto  The notification data
     *
     * @throws \App\Exceptions\NotificationFailedException
     */
    public function notify(NotifyDto $dto): void;
}
