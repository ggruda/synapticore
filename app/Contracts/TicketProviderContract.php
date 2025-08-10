<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\TicketDto;
use App\DTO\TicketWebhookEventDto;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Contract for ticket management system providers (Jira, Linear, Azure DevOps, etc.).
 */
interface TicketProviderContract
{
    /**
     * Fetch a ticket from the external system.
     *
     * @param  string  $externalKey  The external ticket identifier (e.g., JIRA-123)
     * @return TicketDto The ticket data transfer object
     *
     * @throws \App\Exceptions\TicketNotFoundException
     * @throws \App\Exceptions\ProviderConnectionException
     */
    public function fetchTicket(string $externalKey): TicketDto;

    /**
     * Add a comment to a ticket in the external system.
     *
     * @param  string  $externalKey  The external ticket identifier
     * @param  string  $markdownBody  The comment body in Markdown format
     *
     * @throws \App\Exceptions\TicketNotFoundException
     * @throws \App\Exceptions\ProviderConnectionException
     */
    public function addComment(string $externalKey, string $markdownBody): void;

    /**
     * Add a worklog entry to a ticket in the external system.
     *
     * @param  string  $externalKey  The external ticket identifier
     * @param  int  $seconds  The time spent in seconds
     * @param  DateTimeInterface|null  $startedAt  When the work started (null for now)
     * @param  string|null  $comment  Optional worklog comment
     *
     * @throws \App\Exceptions\TicketNotFoundException
     * @throws \App\Exceptions\ProviderConnectionException
     */
    public function addWorklog(
        string $externalKey,
        int $seconds,
        ?DateTimeInterface $startedAt = null,
        ?string $comment = null
    ): void;

    /**
     * Transition a ticket to a new status in the external system.
     *
     * @param  string  $externalKey  The external ticket identifier
     * @param  string  $status  The target status
     *
     * @throws \App\Exceptions\TicketNotFoundException
     * @throws \App\Exceptions\InvalidStatusTransitionException
     * @throws \App\Exceptions\ProviderConnectionException
     */
    public function transitionStatus(string $externalKey, string $status): void;

    /**
     * Parse an incoming webhook request from the ticket system.
     *
     * @param  Request  $request  The incoming webhook request
     * @return TicketWebhookEventDto The parsed webhook event
     *
     * @throws \App\Exceptions\InvalidWebhookPayloadException
     */
    public function parseWebhook(Request $request): TicketWebhookEventDto;
}
