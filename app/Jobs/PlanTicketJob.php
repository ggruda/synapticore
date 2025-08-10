<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiPlannerContract;
use App\Contracts\TicketProviderContract;
use App\DTO\PlanningInputDto;
use App\Models\Plan;
use App\Models\Ticket;
use App\Services\Context\EmbeddingIndexer;
use App\Services\Tickets\TicketCommentFormatter;
use App\Services\Time\TrackedSection;
use App\Services\Validation\SchemaValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to generate AI plan for a ticket.
 * Builds RAG context, calls AI planner, validates, stores plan.
 */
class PlanTicketJob implements ShouldQueue
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
        public bool $postComment = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AiPlannerContract $planner,
        TicketProviderContract $ticketProvider,
        EmbeddingIndexer $embeddingIndexer,
        SchemaValidator $validator,
        TrackedSection $tracker,
    ): void {
        Log::info('Starting plan generation for ticket', [
            'ticket_id' => $this->ticket->id,
            'external_key' => $this->ticket->external_key,
        ]);

        try {
            // Track time for the planning phase
            $tracker->run($this->ticket, 'plan', function () use ($planner, $ticketProvider, $embeddingIndexer, $validator) {
                // Build RAG context using embeddings
                $context = $this->buildRagContext($embeddingIndexer);

                // Prepare planning input
                $input = new PlanningInputDto(
                    ticket: $this->ticket->toDto(),
                    context: $context,
                );

                // Generate plan using AI
                $planJson = $planner->plan($input);

                // Convert to array for validation
                $planData = [
                    'version' => '1.0',
                    'ticket_id' => $this->ticket->external_key,
                    'summary' => $planJson->summary ?? 'Implementation plan for '.$this->ticket->title,
                    'estimated_hours' => $planJson->estimatedHours,
                    'risk_level' => $planJson->risk,
                    'test_strategy' => $planJson->testStrategy,
                    'steps' => array_map(function ($step, $index) {
                        return [
                            'id' => $step['id'] ?? "step_{$index}",
                            'intent' => $step['intent'] ?? 'modify',
                            'targets' => $step['targets'] ?? [],
                            'rationale' => $step['rationale'] ?? 'Step required for implementation',
                            'acceptance' => $step['acceptance'] ?? ['Step completed successfully'],
                            'estimated_minutes' => $step['estimated_minutes'] ?? 30,
                            'dependencies' => $step['dependencies'] ?? [],
                            'risk_factors' => $step['risk_factors'] ?? [],
                        ];
                    }, $planJson->steps, array_keys($planJson->steps)),
                    'files_affected' => $planJson->filesAffected,
                    'prerequisites' => [
                        'dependencies' => $planJson->dependencies,
                    ],
                    'metadata' => array_merge($planJson->metadata, [
                        'created_at' => now()->toIso8601String(),
                        'context_items' => count($context),
                    ]),
                ];

                // Validate against schema
                $validationResult = $validator->validatePlan($planData);

                // Store plan
                $plan = Plan::updateOrCreate(
                    ['ticket_id' => $this->ticket->id],
                    [
                        'plan_json' => $planData,
                        'risk' => $planJson->risk,
                        'test_strategy' => $planJson->testStrategy,
                    ]
                );

                Log::info('Plan generated and stored', [
                    'ticket_id' => $this->ticket->id,
                    'plan_id' => $plan->id,
                    'risk' => $plan->risk,
                    'steps' => count($planData['steps']),
                ]);

                // Post plan as comment if enabled
                if ($this->postComment && config('synaptic.tickets.post_plan_comment')) {
                    $this->postPlanComment($ticketProvider, $plan);
                }

                // Update workflow state
                if ($this->ticket->workflow) {
                    $this->ticket->workflow->update([
                        'state' => 'PLANNED',
                        'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                            'plan_generated_at' => now()->toIso8601String(),
                            'plan_validation' => $validationResult->toArray(),
                        ]),
                    ]);
                }

                // Dispatch next job in pipeline
                ImplementPlanJob::dispatch($this->ticket, $plan)->delay(now()->addSeconds(5));
            }, 'Generating implementation plan for ticket');
        } catch (\Exception $e) {
            Log::error('Plan generation failed', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update workflow with error
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'FAILED',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'plan_error' => $e->getMessage(),
                        'plan_failed_at' => now()->toIso8601String(),
                    ]),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Build RAG context using embeddings.
     *
     * @return array<array{content: string, relevance: float, source: string}>
     */
    private function buildRagContext(EmbeddingIndexer $embeddingIndexer): array
    {
        $context = [];

        // Search for relevant code using ticket title and body
        $query = $this->ticket->title."\n".$this->ticket->body;

        // Add acceptance criteria to query if available
        if (! empty($this->ticket->acceptance_criteria)) {
            $query .= "\n".implode("\n", $this->ticket->acceptance_criteria);
        }

        // Search embeddings
        $hits = $embeddingIndexer->search($query, k: 20, projectId: $this->ticket->project_id);

        foreach ($hits as $hit) {
            $context[] = [
                'content' => $hit->content,
                'relevance' => $hit->score,
                'source' => $hit->metadata['file_path'] ?? 'unknown',
            ];
        }

        // Add project-specific context
        $project = $this->ticket->project;
        if ($project->language_profile) {
            $context[] = [
                'content' => 'Project uses: '.implode(', ', $project->language_profile['languages'] ?? []),
                'relevance' => 0.8,
                'source' => 'project_profile',
            ];
        }

        Log::info('Built RAG context for planning', [
            'ticket_id' => $this->ticket->id,
            'context_items' => count($context),
            'top_sources' => array_slice(array_column($context, 'source'), 0, 5),
        ]);

        return $context;
    }

    /**
     * Post plan as comment to ticket provider.
     */
    private function postPlanComment(TicketProviderContract $ticketProvider, Plan $plan): void
    {
        try {
            // Create formatter
            $formatter = new TicketCommentFormatter;

            // Convert plan data to PlanJson DTO
            $planDto = new \App\DTO\PlanJson(
                steps: $plan->plan_json['steps'] ?? [],
                testStrategy: $plan->test_strategy,
                risk: $plan->risk,
                estimatedHours: $plan->plan_json['estimated_hours'] ?? 0,
                dependencies: $plan->plan_json['prerequisites']['dependencies'] ?? [],
                filesAffected: $plan->plan_json['files_affected'] ?? [],
                breakingChanges: $plan->plan_json['breaking_changes'] ?? [],
                summary: $plan->plan_json['summary'] ?? 'Implementation plan ready',
                metadata: array_merge($plan->plan_json['metadata'] ?? [], [
                    'references' => $plan->plan_json['references'] ?? [],
                ]),
            );

            // Format the comment
            $comment = $formatter->formatPlanComment(
                $planDto,
                $this->ticket->workflow?->id
            );

            // Post comment with retry logic
            $ticketProvider->addComment($this->ticket->external_key, $comment);

            Log::info('Successfully posted plan as comment', [
                'ticket_id' => $this->ticket->id,
                'external_key' => $this->ticket->external_key,
                'workflow_id' => $this->ticket->workflow?->id,
            ]);

            // Update workflow metadata to track comment posting
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'plan_comment_posted' => true,
                        'plan_comment_posted_at' => now()->toIso8601String(),
                    ]),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to post plan comment', [
                'ticket_id' => $this->ticket->id,
                'external_key' => $this->ticket->external_key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't fail the job if comment posting fails
            // But track the failure in workflow metadata
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'plan_comment_failed' => true,
                        'plan_comment_error' => $e->getMessage(),
                    ]),
                ]);
            }
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PlanTicketJob failed', [
            'ticket_id' => $this->ticket->id,
            'error' => $exception->getMessage(),
        ]);

        // Update workflow state
        if ($this->ticket->workflow) {
            $this->ticket->workflow->update([
                'state' => 'FAILED',
                'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                    'plan_job_failed' => true,
                    'plan_job_error' => $exception->getMessage(),
                ]),
            ]);
        }
    }
}
