<?php

declare(strict_types=1);

namespace App\Http\Controllers\Example;

use App\Contracts\TicketProviderContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\TicketWebhookRequest;
use App\Models\Project;
use App\Services\Example\TicketProcessingService;
use Illuminate\Http\JsonResponse;

/**
 * Example controller demonstrating proper use of contracts and services.
 *
 * Controllers should:
 * - Handle only HTTP concerns (request/response)
 * - Delegate business logic to services
 * - Use Form Requests for validation
 * - Work with contracts, not concrete implementations
 */
final class TicketWebhookController extends Controller
{
    public function __construct(
        private readonly TicketProviderContract $ticketProvider,
        private readonly TicketProcessingService $processingService,
    ) {}

    /**
     * Handle incoming webhook from ticket system.
     *
     * @param  TicketWebhookRequest  $request  Validated webhook request
     * @param  Project  $project  The project receiving the webhook
     */
    public function handle(TicketWebhookRequest $request, Project $project): JsonResponse
    {
        // Parse webhook using contract
        $webhookEvent = $this->ticketProvider->parseWebhook($request);

        // Process the ticket through service
        $ticket = $this->processingService->processExternalTicket(
            $project,
            $webhookEvent->externalKey
        );

        // Return only HTTP response concerns
        return response()->json([
            'status' => 'success',
            'message' => 'Webhook processed successfully',
            'data' => [
                'ticket_id' => $ticket->id,
                'external_key' => $webhookEvent->externalKey,
                'event_type' => $webhookEvent->eventType,
            ],
        ], 200);
    }
}
