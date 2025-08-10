<?php

declare(strict_types=1);

namespace App\Services\Example;

use App\Contracts\AiPlannerContract;
use App\Contracts\NotificationChannelContract;
use App\Contracts\TicketProviderContract;
use App\DTO\NotifyDto;
use App\DTO\PlanningInputDto;
use App\DTO\TicketDto;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Example service demonstrating the use of contracts and DTOs.
 *
 * This service follows strict MVC principles:
 * - Uses only contracts/interfaces, not concrete implementations
 * - Works with immutable DTOs for data transfer
 * - Contains business logic separate from models
 */
final class TicketProcessingService
{
    public function __construct(
        private readonly TicketProviderContract $ticketProvider,
        private readonly AiPlannerContract $planner,
        private readonly NotificationChannelContract $notifier,
    ) {}

    /**
     * Process a ticket from an external system.
     *
     * @param  Project  $project  The project context
     * @param  string  $externalKey  The external ticket key
     * @return Ticket The processed ticket model
     *
     * @throws \Exception
     */
    public function processExternalTicket(Project $project, string $externalKey): Ticket
    {
        // Fetch ticket from external provider using contract
        $ticketDto = $this->ticketProvider->fetchTicket($externalKey);

        // Start transaction for data consistency
        return DB::transaction(function () use ($project, $ticketDto) {
            // Create or update ticket model from DTO
            $ticket = $this->createOrUpdateTicket($project, $ticketDto);

            // Generate AI plan using contract
            $planDto = $this->generatePlan($ticket, $ticketDto);

            // Store plan
            $ticket->plan()->create([
                'plan_json' => $planDto->toArray(),
                'risk' => $planDto->riskLevel(),
                'test_strategy' => $planDto->testStrategy,
            ]);

            // Send notification using contract
            $this->notifyTicketProcessed($ticket, $ticketDto);

            return $ticket;
        });
    }

    /**
     * Create or update ticket from DTO.
     */
    private function createOrUpdateTicket(Project $project, TicketDto $dto): Ticket
    {
        return Ticket::updateOrCreate(
            [
                'external_key' => $dto->externalKey,
                'project_id' => $project->id,
            ],
            [
                'source' => $dto->source,
                'title' => $dto->title,
                'body' => $dto->body,
                'acceptance_criteria' => $dto->acceptanceCriteria,
                'labels' => $dto->labels,
                'status' => $dto->status,
                'priority' => $dto->priority,
                'meta' => $dto->meta,
            ]
        );
    }

    /**
     * Generate plan using AI planner contract.
     */
    private function generatePlan(Ticket $ticket, TicketDto $ticketDto): \App\DTO\PlanJson
    {
        $planningInput = new PlanningInputDto(
            ticket: $ticketDto,
            repositoryPath: $ticket->project->repo_urls[0] ?? '',
            contextFiles: [],
            languageProfile: $ticket->project->language_profile,
            allowedPaths: $ticket->project->allowed_paths,
            additionalContext: null,
            constraints: [],
            maxSteps: 10,
        );

        return $this->planner->plan($planningInput);
    }

    /**
     * Send notification about processed ticket.
     */
    private function notifyTicketProcessed(Ticket $ticket, TicketDto $dto): void
    {
        $notification = new NotifyDto(
            title: 'Ticket Processed',
            message: "Ticket {$dto->externalKey} has been processed and planned.",
            level: NotifyDto::LEVEL_INFO,
            channels: [NotifyDto::CHANNEL_SLACK],
            recipients: [],
            data: [
                'ticket_id' => $ticket->id,
                'external_key' => $dto->externalKey,
                'priority' => $dto->priority,
            ],
            actionUrl: "/tickets/{$ticket->id}",
            actionText: 'View Ticket',
            attachments: [],
            metadata: [],
        );

        $this->notifier->notify($notification);
    }
}
