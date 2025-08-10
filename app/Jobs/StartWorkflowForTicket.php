<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiPlannerContract;
use App\Contracts\NotificationChannelContract;
use App\Contracts\TicketProviderContract;
use App\DTO\NotifyDto;
use App\DTO\PlanningInputDto;
use App\DTO\TicketDto;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\Workflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to start the workflow for a ticket.
 * Contains the business logic for processing tickets.
 */
class StartWorkflowForTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Ticket $ticket,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        TicketProviderContract $ticketProvider,
        AiPlannerContract $planner,
        NotificationChannelContract $notifier,
    ): void {
        Log::info('Starting workflow for ticket', [
            'ticket_id' => $this->ticket->id,
            'external_key' => $this->ticket->external_key,
        ]);

        try {
            // Start database transaction
            DB::transaction(function () use ($ticketProvider, $planner, $notifier) {
                // Create or update workflow
                $workflow = $this->createOrUpdateWorkflow();

                // If workflow is already in progress or completed, skip
                if ($this->shouldSkipWorkflow($workflow)) {
                    Log::info('Skipping workflow, already in progress or completed', [
                        'ticket_id' => $this->ticket->id,
                        'workflow_state' => $workflow->state,
                    ]);

                    return;
                }

                // Update workflow state to CONTEXT_READY
                $workflow->update([
                    'state' => Workflow::STATE_CONTEXT_READY,
                    'retries' => $workflow->retries + 1,
                ]);

                // Generate plan if not exists
                if (! $this->ticket->plan) {
                    $this->generatePlan($planner, $workflow);
                }

                // Post plan as comment if configured
                if (config('synaptic.tickets.post_plan_comment', true) && $this->ticket->plan) {
                    $this->postPlanComment($ticketProvider);
                }

                // Update workflow state to PLANNED
                $workflow->update(['state' => Workflow::STATE_PLANNED]);

                // Notify about workflow start
                $this->sendNotification($notifier, 'started');

                // Dispatch next job in the workflow
                $this->dispatchNextJob();
            });
        } catch (\Exception $e) {
            Log::error('Failed to start workflow for ticket', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update workflow state to FAILED
            $this->ticket->workflow?->update([
                'state' => Workflow::STATE_FAILED,
                'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]);

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Create or update workflow for the ticket.
     */
    private function createOrUpdateWorkflow(): Workflow
    {
        return Workflow::firstOrCreate(
            ['ticket_id' => $this->ticket->id],
            [
                'state' => Workflow::STATE_INGESTED,
                'retries' => 0,
            ]
        );
    }

    /**
     * Check if workflow should be skipped.
     */
    private function shouldSkipWorkflow(Workflow $workflow): bool
    {
        // Skip if already completed or in final states
        $finalStates = [
            Workflow::STATE_DONE,
            Workflow::STATE_PR_CREATED,
        ];

        if (in_array($workflow->state, $finalStates)) {
            return true;
        }

        // Skip if failed too many times
        if ($workflow->state === Workflow::STATE_FAILED && $workflow->retries >= 3) {
            return true;
        }

        return false;
    }

    /**
     * Generate plan using AI planner.
     */
    private function generatePlan(AiPlannerContract $planner, Workflow $workflow): void
    {
        Log::info('Generating plan for ticket', ['ticket_id' => $this->ticket->id]);

        // Create TicketDto from model
        $ticketDto = new TicketDto(
            externalKey: $this->ticket->external_key,
            title: $this->ticket->title,
            body: $this->ticket->body,
            status: $this->ticket->status,
            priority: $this->ticket->priority,
            source: $this->ticket->source,
            labels: $this->ticket->labels ?? [],
            acceptanceCriteria: $this->ticket->acceptance_criteria ?? [],
            meta: $this->ticket->meta ?? [],
        );

        // Create planning input
        $planningInput = new PlanningInputDto(
            ticket: $ticketDto,
            repositoryPath: $this->ticket->project->repo_urls[0] ?? '',
            contextFiles: [],
            languageProfile: $this->ticket->project->language_profile ?? [],
            allowedPaths: $this->ticket->project->allowed_paths ?? [],
            additionalContext: null,
            constraints: [],
            maxSteps: 10,
        );

        // Generate plan
        $planResult = $planner->plan($planningInput);

        // Save plan
        Plan::create([
            'ticket_id' => $this->ticket->id,
            'plan_json' => $planResult->toArray(),
            'risk' => $planResult->riskLevel(),
            'test_strategy' => $planResult->testStrategy,
        ]);

        Log::info('Plan generated successfully', [
            'ticket_id' => $this->ticket->id,
            'risk' => $planResult->riskLevel(),
            'steps' => count($planResult->steps),
        ]);
    }

    /**
     * Post plan as comment to external ticket system.
     */
    private function postPlanComment(TicketProviderContract $ticketProvider): void
    {
        $plan = $this->ticket->plan;

        if (! $plan) {
            return;
        }

        $comment = "## 🤖 Synapticore Bot - Plan Generated\n\n";
        $comment .= "### Summary\n";
        $comment .= ($plan->plan_json['summary'] ?? 'Plan generated for implementation')."\n\n";

        $comment .= "### Steps\n";
        foreach ($plan->plan_json['steps'] ?? [] as $index => $step) {
            $comment .= ($index + 1).'. '.$step."\n";
        }

        $comment .= "\n### Details\n";
        $comment .= '- **Risk Level**: '.ucfirst($plan->risk)."\n";
        $comment .= '- **Test Strategy**: '.$plan->test_strategy."\n";
        $comment .= '- **Estimated Hours**: '.($plan->plan_json['estimated_hours'] ?? 'N/A')."\n";

        if (! empty($plan->plan_json['files_affected'])) {
            $comment .= "\n### Files Affected\n";
            foreach ($plan->plan_json['files_affected'] as $file) {
                $comment .= '- `'.$file."`\n";
            }
        }

        try {
            $ticketProvider->addComment($this->ticket->external_key, $comment);
            Log::info('Posted plan comment to ticket', ['ticket_id' => $this->ticket->id]);
        } catch (\Exception $e) {
            // Log error but don't fail the job
            Log::error('Failed to post plan comment', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification about workflow status.
     */
    private function sendNotification(NotificationChannelContract $notifier, string $status): void
    {
        $notification = new NotifyDto(
            title: 'Workflow '.ucfirst($status),
            message: "Workflow {$status} for ticket {$this->ticket->external_key}: {$this->ticket->title}",
            level: $status === 'failed' ? NotifyDto::LEVEL_ERROR : NotifyDto::LEVEL_INFO,
            channels: [],
            recipients: [],
            data: [
                'ticket_id' => $this->ticket->id,
                'external_key' => $this->ticket->external_key,
                'workflow_state' => $this->ticket->workflow?->state,
            ],
            actionUrl: config('app.url').'/tickets/'.$this->ticket->id,
            actionText: 'View Ticket',
        );

        try {
            $notifier->notify($notification);
        } catch (\Exception $e) {
            // Log error but don't fail the job
            Log::error('Failed to send notification', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch the next job in the workflow.
     */
    private function dispatchNextJob(): void
    {
        // TODO: Implement workflow progression
        // For now, just log
        Log::info('Ready to dispatch next workflow job', [
            'ticket_id' => $this->ticket->id,
            'workflow_state' => $this->ticket->workflow?->state,
        ]);

        // Future jobs to dispatch based on workflow state:
        // - ImplementTicketJob::dispatch($this->ticket);
        // - TestImplementationJob::dispatch($this->ticket);
        // - ReviewCodeJob::dispatch($this->ticket);
        // - CreatePullRequestJob::dispatch($this->ticket);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('StartWorkflowForTicket job failed', [
            'ticket_id' => $this->ticket->id,
            'error' => $exception->getMessage(),
        ]);

        // Update workflow state
        $this->ticket->workflow?->update([
            'state' => Workflow::STATE_FAILED,
            'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                'job_error' => $exception->getMessage(),
                'job_failed_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
