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
        $data = $request->all();

        // Map Jira webhook event types
        $eventType = match ($data['webhookEvent'] ?? '') {
            'jira:issue_created' => TicketWebhookEventDto::EVENT_CREATED,
            'jira:issue_updated' => TicketWebhookEventDto::EVENT_UPDATED,
            'jira:issue_deleted' => TicketWebhookEventDto::EVENT_UPDATED,
            'comment_created' => TicketWebhookEventDto::EVENT_COMMENTED,
            default => TicketWebhookEventDto::EVENT_UPDATED,
        };

        // Extract issue data
        $issue = $data['issue'] ?? [];
        $fields = $issue['fields'] ?? [];

        // Create TicketDto from webhook data
        $ticket = new TicketDto(
            externalKey: $issue['key'] ?? '',
            title: $fields['summary'] ?? '',
            body: $this->extractDescription($fields['description'] ?? ''),
            status: $fields['status']['name'] ?? 'unknown',
            priority: $fields['priority']['name'] ?? 'medium',
            source: 'jira',
            labels: $fields['labels'] ?? [],
            acceptanceCriteria: $this->extractAcceptanceCriteria($fields),
            meta: [
                'issue_type' => $fields['issuetype']['name'] ?? null,
                'project' => $fields['project']['key'] ?? null,
                'created' => $fields['created'] ?? null,
                'updated' => $fields['updated'] ?? null,
            ],
            assignee: $fields['assignee']['emailAddress'] ?? null,
            reporter: $fields['reporter']['emailAddress'] ?? null,
            storyPoints: $fields['customfield_10016'] ?? null,
            sprint: $this->extractSprint($fields),
        );

        return new TicketWebhookEventDto(
            eventType: $eventType,
            externalKey: $issue['key'] ?? '',
            ticket: $ticket,
            changes: $data['changelog']['items'] ?? [],
            comment: $data['comment']['body'] ?? null,
            triggeredBy: $data['user']['emailAddress'] ?? null,
            occurredAt: isset($data['timestamp'])
                ? new \DateTimeImmutable('@'.($data['timestamp'] / 1000))
                : new \DateTimeImmutable,
        );
    }

    /**
     * Extract description from Jira's Atlassian Document Format.
     */
    private function extractDescription($description): string
    {
        if (is_string($description)) {
            return $description;
        }

        if (is_array($description) && isset($description['content'])) {
            $text = '';
            foreach ($description['content'] as $block) {
                if (isset($block['content'])) {
                    foreach ($block['content'] as $inline) {
                        if (isset($inline['text'])) {
                            $text .= $inline['text']."\n";
                        }
                    }
                }
            }

            return trim($text);
        }

        return '';
    }

    /**
     * Extract acceptance criteria from custom fields.
     */
    private function extractAcceptanceCriteria(array $fields): array
    {
        $criteriaField = $fields['customfield_10020'] ?? null;

        if (! $criteriaField) {
            return [];
        }

        if (is_string($criteriaField)) {
            return array_filter(
                array_map('trim', explode("\n", strip_tags($criteriaField))),
                fn ($line) => ! empty($line)
            );
        }

        return [];
    }

    /**
     * Extract sprint information from custom fields.
     */
    private function extractSprint(array $fields): ?string
    {
        $sprintField = $fields['customfield_10021'] ?? $fields['sprint'] ?? null;

        if (is_array($sprintField) && ! empty($sprintField)) {
            $sprint = $sprintField[0];
            if (is_array($sprint) && isset($sprint['name'])) {
                return $sprint['name'];
            }
            if (is_string($sprint)) {
                // Parse sprint string format: com.atlassian.greenhopper.service.sprint.Sprint@[name=Sprint 1,...]
                if (preg_match('/name=([^,\]]+)/', $sprint, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
