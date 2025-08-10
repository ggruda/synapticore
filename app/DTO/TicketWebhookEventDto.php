<?php

declare(strict_types=1);

namespace App\DTO;

use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\Data;

/**
 * Immutable data transfer object for ticket webhook events.
 */
#[Immutable]
final class TicketWebhookEventDto extends Data
{
    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_COMMENTED = 'commented';

    public const EVENT_ASSIGNED = 'assigned';

    public function __construct(
        public readonly string $eventType,
        public readonly string $externalKey,
        public readonly TicketDto $ticket,
        public readonly array $changes = [],
        public readonly ?string $comment = null,
        public readonly ?string $triggeredBy = null,
        public readonly ?\DateTimeImmutable $occurredAt = null,
    ) {}
}
