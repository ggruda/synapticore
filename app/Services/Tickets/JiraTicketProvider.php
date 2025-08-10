<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Contracts\TicketProviderContract;
use App\DTO\TicketDto;
use App\DTO\TicketWebhookEventDto;
use App\Exceptions\NotImplementedException;
use App\Exceptions\ProviderConnectionException;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $maxRetries = 3;
        $retryDelay = 1; // Start with 1 second

        // Convert markdown to Jira's Atlassian Document Format (ADF)
        $commentBody = $this->convertMarkdownToJiraFormat($markdownBody);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withBasicAuth($this->username, $this->token)
                    ->timeout(30)
                    ->post("{$this->url}/rest/api/2/issue/{$externalKey}/comment", [
                        'body' => $commentBody,
                    ]);

                if ($response->successful()) {
                    Log::info('Successfully posted comment to Jira', [
                        'issue' => $externalKey,
                        'attempt' => $attempt,
                        'comment_id' => $response->json('id'),
                    ]);

                    return;
                }

                // Check if it's a retryable error
                if ($response->status() >= 500 || $response->status() === 429) {
                    if ($attempt < $maxRetries) {
                        Log::warning('Retryable error posting comment to Jira', [
                            'issue' => $externalKey,
                            'attempt' => $attempt,
                            'status' => $response->status(),
                            'error' => $response->body(),
                        ]);

                        // Exponential backoff
                        sleep($retryDelay);
                        $retryDelay *= 2;

                        continue;
                    }
                }

                // Non-retryable error
                throw new ProviderConnectionException(
                    "Failed to post comment to Jira issue {$externalKey}: ".
                    "HTTP {$response->status()} - {$response->body()}"
                );

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Network error - retry if attempts remain
                if ($attempt < $maxRetries) {
                    Log::warning('Network error posting comment to Jira, retrying...', [
                        'issue' => $externalKey,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    sleep($retryDelay);
                    $retryDelay *= 2;

                    continue;
                }

                throw new ProviderConnectionException(
                    "Network error posting comment to Jira issue {$externalKey}: ".$e->getMessage(),
                    0,
                    $e
                );
            }
        }

        // If we get here, all retries failed
        throw new ProviderConnectionException(
            "Failed to post comment to Jira issue {$externalKey} after {$maxRetries} attempts"
        );
    }

    /**
     * Convert markdown to Jira's format.
     */
    private function convertMarkdownToJiraFormat(string $markdown): string
    {
        // Jira uses a simplified wiki markup or ADF (Atlassian Document Format)
        // For now, we'll use the simple text format which Jira accepts
        // In production, you'd want to convert to proper ADF JSON

        // Basic markdown to Jira wiki conversions
        $converted = $markdown;

        // Headers
        $converted = preg_replace('/^### (.+)$/m', 'h3. $1', $converted);
        $converted = preg_replace('/^## (.+)$/m', 'h2. $1', $converted);
        $converted = preg_replace('/^# (.+)$/m', 'h1. $1', $converted);

        // Bold
        $converted = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $converted);

        // Italic
        $converted = preg_replace('/_(.+?)_/', '_$1_', $converted);

        // Code blocks
        $converted = preg_replace('/```(\w+)?\n(.*?)\n```/s', '{code:$1}$2{code}', $converted);

        // Inline code
        $converted = preg_replace('/`(.+?)`/', '{{$1}}', $converted);

        // Links
        $converted = preg_replace('/\[(.+?)\]\((.+?)\)/', '[$1|$2]', $converted);

        // Unordered lists
        $converted = preg_replace('/^- (.+)$/m', '* $1', $converted);

        // Checkboxes
        $converted = preg_replace('/^- \[ \] (.+)$/m', '* {-} $1', $converted);
        $converted = preg_replace('/^- \[x\] (.+)$/m', '* {+} $1', $converted);

        // Emojis to Jira equivalents (common ones)
        $emojiMap = [
            '✅' => '(/)',
            '❌' => '(x)',
            '⚠️' => '(!)',
            '📝' => '(i)',
            '🔗' => '(link)',
            '⏱️' => '(clock)',
            '🤖' => '(robot)',
            '🧪' => '(test)',
            '📋' => '(clipboard)',
            '📦' => '(package)',
            '🟢' => '(green)',
            '🟡' => '(yellow)',
            '🟠' => '(orange)',
            '🔴' => '(red)',
            '⚪' => '(gray)',
        ];

        foreach ($emojiMap as $emoji => $jira) {
            $converted = str_replace($emoji, $jira, $converted);
        }

        return $converted;
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
