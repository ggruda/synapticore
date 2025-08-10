<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Contracts\TicketProviderContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\JiraWebhookRequest;
use App\Jobs\StartWorkflowForTicket;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming Jira webhooks (strict MVC).
 * Only HTTP concerns - business logic is in services/jobs.
 */
class JiraWebhookController extends Controller
{
    public function __construct(
        private readonly TicketProviderContract $ticketProvider,
    ) {}

    /**
     * Handle incoming Jira webhook.
     *
     * @param  JiraWebhookRequest  $request  Validated webhook request
     * @param  Project  $project  The project receiving the webhook
     */
    public function handle(JiraWebhookRequest $request, Project $project): JsonResponse
    {
        Log::info('Jira webhook received', [
            'project_id' => $project->id,
            'event' => $request->input('webhookEvent'),
            'issue_key' => $request->input('issue.key'),
        ]);

        try {
            // Parse webhook using contract
            $webhookEvent = $this->ticketProvider->parseWebhook($request);

            // Process ticket in database transaction
            $ticket = DB::transaction(function () use ($project, $webhookEvent) {
                // Create or update ticket
                $ticket = Ticket::updateOrCreate(
                    [
                        'external_key' => $webhookEvent->externalKey,
                        'project_id' => $project->id,
                    ],
                    [
                        'source' => $webhookEvent->ticket->source,
                        'title' => $webhookEvent->ticket->title,
                        'body' => $webhookEvent->ticket->body,
                        'acceptance_criteria' => $webhookEvent->ticket->acceptanceCriteria,
                        'labels' => $webhookEvent->ticket->labels,
                        'status' => $webhookEvent->ticket->status,
                        'priority' => $webhookEvent->ticket->priority,
                        'meta' => array_merge(
                            $webhookEvent->ticket->meta,
                            [
                                'last_webhook_event' => $webhookEvent->eventType,
                                'last_webhook_at' => now()->toIso8601String(),
                            ]
                        ),
                    ]
                );

                return $ticket;
            });

            // Dispatch workflow job
            StartWorkflowForTicket::dispatch($ticket);

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'data' => [
                    'ticket_id' => $ticket->id,
                    'external_key' => $webhookEvent->externalKey,
                    'event_type' => $webhookEvent->eventType,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Jira webhook processing failed', [
                'project_id' => $project->id,
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
}
