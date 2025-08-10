<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Contracts\TicketProviderContract;
use App\DTO\TicketDto;
use App\DTO\TicketWebhookEventDto;
use App\Exceptions\NotImplementedException;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Linear skeleton implementation of the ticket provider contract.
 */
class LinearTicketProvider implements TicketProviderContract
{
    public function __construct(
        private readonly string $apiKey,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function fetchTicket(string $externalKey): TicketDto
    {
        throw new NotImplementedException('LinearTicketProvider::fetchTicket() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function addComment(string $externalKey, string $markdownBody): void
    {
        throw new NotImplementedException('LinearTicketProvider::addComment() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function addWorklog(
        string $externalKey,
        int $seconds,
        ?DateTimeInterface $startedAt = null,
        ?string $comment = null
    ): void {
        throw new NotImplementedException('LinearTicketProvider::addWorklog() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function transitionStatus(string $externalKey, string $status): void
    {
        throw new NotImplementedException('LinearTicketProvider::transitionStatus() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function parseWebhook(Request $request): TicketWebhookEventDto
    {
        throw new NotImplementedException('LinearTicketProvider::parseWebhook() not yet implemented');
    }
}
