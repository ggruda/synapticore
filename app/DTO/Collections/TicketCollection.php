<?php

declare(strict_types=1);

namespace App\DTO\Collections;

use App\DTO\TicketDto;
use Spatie\LaravelData\Attributes\Immutable;
use Spatie\LaravelData\DataCollection;

/**
 * Immutable collection of TicketDto objects.
 *
 * @extends DataCollection<int, TicketDto>
 */
#[Immutable]
final class TicketCollection extends DataCollection
{
    public static string $dataClass = TicketDto::class;

    /**
     * Filter tickets by status.
     */
    public function byStatus(string $status): self
    {
        return $this->filter(fn (TicketDto $ticket) => $ticket->status === $status);
    }

    /**
     * Filter tickets by priority.
     */
    public function byPriority(string $priority): self
    {
        return $this->filter(fn (TicketDto $ticket) => $ticket->priority === $priority);
    }

    /**
     * Get high priority tickets.
     */
    public function highPriority(): self
    {
        return $this->filter(fn (TicketDto $ticket) => in_array($ticket->priority, ['high', 'critical']));
    }
}
