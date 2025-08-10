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
 * Jira skeleton implementation of the ticket provider contract.
 */
class JiraTicketProvider implements TicketProviderContract
{
    public function __construct(
        private readonly string $url,
        private readonly string $username,
        private readonly string $token,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function fetchTicket(string $externalKey): TicketDto
    {
        // TODO: Implement Jira API integration
        throw new NotImplementedException('JiraTicketProvider::fetchTicket() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function addComment(string $externalKey, string $markdownBody): void
    {
        // TODO: Implement comment addition via Jira API
        throw new NotImplementedException('JiraTicketProvider::addComment() not yet implemented');
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
        // TODO: Implement worklog addition via Jira API
        throw new NotImplementedException('JiraTicketProvider::addWorklog() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function transitionStatus(string $externalKey, string $status): void
    {
        // TODO: Implement status transition via Jira API
        throw new NotImplementedException('JiraTicketProvider::transitionStatus() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function parseWebhook(Request $request): TicketWebhookEventDto
    {
        // TODO: Implement webhook parsing
        throw new NotImplementedException('JiraTicketProvider::parseWebhook() not yet implemented');
    }
}
