<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\GithubWebhookRequest;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming GitHub webhooks (strict MVC).
 * Only HTTP concerns - business logic is in services/jobs.
 */
class GithubWebhookController extends Controller
{
    /**
     * Handle incoming GitHub webhook.
     *
     * @param  GithubWebhookRequest  $request  Validated webhook request
     * @param  Project  $project  The project receiving the webhook
     */
    public function handle(GithubWebhookRequest $request, Project $project): JsonResponse
    {
        $event = $request->header('X-GitHub-Event');

        Log::info('GitHub webhook received', [
            'project_id' => $project->id,
            'event' => $event,
            'action' => $request->input('action'),
        ]);

        try {
            $result = match ($event) {
                'pull_request' => $this->handlePullRequest($request, $project),
                'push' => $this->handlePush($request, $project),
                'issues' => $this->handleIssue($request, $project),
                'issue_comment' => $this->handleIssueComment($request, $project),
                default => ['message' => 'Event type not handled: '.$event],
            };

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'data' => $result,
            ], 200);
        } catch (\Exception $e) {
            Log::error('GitHub webhook processing failed', [
                'project_id' => $project->id,
                'event' => $event,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process webhook',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle pull request events.
     */
    private function handlePullRequest(GithubWebhookRequest $request, Project $project): array
    {
        $prData = $request->input('pull_request');
        $action = $request->input('action');

        // Extract ticket reference from PR title/body/branch
        $ticketKey = $this->extractTicketKey(
            $prData['title'] ?? '',
            $prData['body'] ?? '',
            $prData['head']['ref'] ?? ''
        );

        if (! $ticketKey) {
            return ['message' => 'No ticket reference found in PR'];
        }

        $ticket = Ticket::where('project_id', $project->id)
            ->where('external_key', $ticketKey)
            ->first();

        if (! $ticket) {
            return ['message' => 'Ticket not found: '.$ticketKey];
        }

        // Update or create pull request record
        DB::transaction(function () use ($ticket, $prData, $action) {
            PullRequest::updateOrCreate(
                [
                    'ticket_id' => $ticket->id,
                    'provider_id' => (string) $prData['id'],
                ],
                [
                    'url' => $prData['html_url'],
                    'branch_name' => $prData['head']['ref'],
                    'is_draft' => $prData['draft'] ?? false,
                    'labels' => array_map(fn ($label) => $label['name'], $prData['labels'] ?? []),
                ]
            );

            // Update ticket meta
            $ticket->update([
                'meta' => array_merge($ticket->meta ?? [], [
                    'pr_status' => $prData['state'],
                    'pr_action' => $action,
                    'pr_updated_at' => now()->toIso8601String(),
                ]),
            ]);
        });

        return [
            'ticket_id' => $ticket->id,
            'pr_id' => $prData['id'],
            'action' => $action,
        ];
    }

    /**
     * Handle push events.
     */
    private function handlePush(GithubWebhookRequest $request, Project $project): array
    {
        // Log push event but don't process further for now
        return [
            'message' => 'Push event received',
            'ref' => $request->input('ref'),
            'commits' => count($request->input('commits', [])),
        ];
    }

    /**
     * Handle issue events.
     */
    private function handleIssue(GithubWebhookRequest $request, Project $project): array
    {
        // GitHub issues can be used as an alternative ticket source
        return [
            'message' => 'Issue event received',
            'issue' => $request->input('issue.number'),
            'action' => $request->input('action'),
        ];
    }

    /**
     * Handle issue comment events.
     */
    private function handleIssueComment(GithubWebhookRequest $request, Project $project): array
    {
        return [
            'message' => 'Issue comment received',
            'issue' => $request->input('issue.number'),
            'comment' => substr($request->input('comment.body', ''), 0, 100),
        ];
    }

    /**
     * Extract ticket key from PR title, body, or branch name.
     */
    private function extractTicketKey(string $title, string $body, string $branch): ?string
    {
        // Look for patterns like JIRA-123, LINEAR-456, etc.
        $pattern = '/\b([A-Z]{2,10}-\d{1,6})\b/';

        // Check title first
        if (preg_match($pattern, $title, $matches)) {
            return $matches[1];
        }

        // Check body
        if (preg_match($pattern, $body, $matches)) {
            return $matches[1];
        }

        // Check branch name
        if (preg_match($pattern, $branch, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
