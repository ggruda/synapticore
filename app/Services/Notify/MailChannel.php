<?php

declare(strict_types=1);

namespace App\Services\Notify;

use App\Contracts\NotificationChannelContract;
use App\DTO\NotifyDto;
use App\Exceptions\NotImplementedException;
use Illuminate\Support\Facades\Log;

/**
 * Email skeleton implementation of the notification channel contract.
 */
class MailChannel implements NotificationChannelContract
{
    public function __construct()
    {
        // Constructor for any future dependencies
    }

    /**
     * {@inheritDoc}
     */
    public function notify(NotifyDto $dto): void
    {
        // TODO: Implement email notification sending

        // For now, just log the notification
        Log::info('MailChannel::notify() skeleton called', [
            'title' => $dto->title,
            'message' => $dto->message,
            'level' => $dto->level,
            'recipients' => $dto->recipients,
        ]);

        // Optionally throw NotImplementedException if you prefer
        // throw new NotImplementedException('MailChannel::notify() not yet implemented');
    }
}
