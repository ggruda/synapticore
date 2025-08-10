<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\BuildContextJob;
use App\Jobs\ImplementPlanJob;
use App\Jobs\PlanTicketJob;
use App\Jobs\ReviewPatchJob;
use App\Jobs\RunChecksJob;
use App\Models\Ticket;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service to orchestrate the workflow pipeline.
 * Manages state transitions and job dispatching.
 */
class WorkflowOrchestrator
{
    /**
     * State transition map.
     * Defines valid transitions from each state.
     */
    private const STATE_TRANSITIONS = [
        Workflow::STATE_INGESTED => [Workflow::STATE_CONTEXT_READY],
        Workflow::STATE_CONTEXT_READY => [Workflow::STATE_PLANNED],
        Workflow::STATE_PLANNED => [Workflow::STATE_IMPLEMENTING],
        Workflow::STATE_IMPLEMENTING => [Workflow::STATE_TESTING],
        Workflow::STATE_TESTING => [Workflow::STATE_REVIEWING, Workflow::STATE_FIXING],
        Workflow::STATE_REVIEWING => [Workflow::STATE_FIXING, Workflow::STATE_PR_CREATED],
        Workflow::STATE_FIXING => [Workflow::STATE_TESTING],
        Workflow::STATE_PR_CREATED => [Workflow::STATE_DONE],
        Workflow::STATE_DONE => [],
        Workflow::STATE_FAILED => [Workflow::STATE_INGESTED], // Allow retry
    ];

    /**
     * Start workflow for a ticket.
     */
    public function startWorkflow(Ticket $ticket): Workflow
    {
        Log::info('Starting workflow orchestration', [
            'ticket_id' => $ticket->id,
            'external_key' => $ticket->external_key,
        ]);

        // Create or get workflow
        $workflow = $ticket->workflow ?? Workflow::create([
            'ticket_id' => $ticket->id,
            'state' => Workflow::STATE_INGESTED,
            'retries' => 0,
        ]);

        // Dispatch first job based on current state
        $this->dispatchNextJob($workflow);

        return $workflow;
    }

    /**
     * Transition workflow to next state.
     *
     * @throws \Exception if transition is invalid
     */
    public function transitionTo(Workflow $workflow, string $newState): void
    {
        $currentState = $workflow->state;

        // Check if transition is valid
        if (! $this->canTransitionTo($currentState, $newState)) {
            throw new \Exception(
                "Invalid state transition from {$currentState} to {$newState}"
            );
        }

        Log::info('Transitioning workflow state', [
            'workflow_id' => $workflow->id,
            'from' => $currentState,
            'to' => $newState,
        ]);

        // Update state atomically
        DB::transaction(function () use ($workflow, $newState, $currentState) {
            $workflow->update([
                'state' => $newState,
                'meta' => array_merge($workflow->meta ?? [], [
                    'previous_state' => $currentState,
                    'transitioned_at' => now()->toIso8601String(),
                ]),
            ]);
        });

        // Dispatch next job if needed
        if (! in_array($newState, [Workflow::STATE_DONE, Workflow::STATE_FAILED])) {
            $this->dispatchNextJob($workflow);
        }
    }

    /**
     * Check if transition is valid.
     */
    public function canTransitionTo(string $fromState, string $toState): bool
    {
        $validTransitions = self::STATE_TRANSITIONS[$fromState] ?? [];

        return in_array($toState, $validTransitions);
    }

    /**
     * Dispatch next job based on current state.
     */
    public function dispatchNextJob(Workflow $workflow): void
    {
        $ticket = $workflow->ticket;

        Log::info('Dispatching job for workflow state', [
            'workflow_id' => $workflow->id,
            'state' => $workflow->state,
        ]);

        switch ($workflow->state) {
            case Workflow::STATE_INGESTED:
                // Build context first
                BuildContextJob::dispatch($ticket)
                    ->delay(now()->addSeconds(5));
                break;

            case Workflow::STATE_CONTEXT_READY:
                // Generate plan
                PlanTicketJob::dispatch($ticket)
                    ->delay(now()->addSeconds(5));
                break;

            case Workflow::STATE_PLANNED:
                // Implement plan
                if ($ticket->plan) {
                    ImplementPlanJob::dispatch($ticket, $ticket->plan)
                        ->delay(now()->addSeconds(5));
                } else {
                    Log::error('No plan found for implementation', [
                        'ticket_id' => $ticket->id,
                    ]);
                    $this->markAsFailed($workflow, 'No plan found');
                }
                break;

            case Workflow::STATE_IMPLEMENTING:
                // Run checks
                if ($ticket->patches()->exists()) {
                    $patch = $ticket->patches()->latest()->first();
                    RunChecksJob::dispatch($ticket, $patch)
                        ->delay(now()->addSeconds(5));
                } else {
                    Log::error('No patch found for testing', [
                        'ticket_id' => $ticket->id,
                    ]);
                    $this->markAsFailed($workflow, 'No patch found');
                }
                break;

            case Workflow::STATE_TESTING:
                // Review patch
                if ($ticket->patches()->exists()) {
                    $patch = $ticket->patches()->latest()->first();
                    $checksPass = $this->checksPass($ticket);
                    ReviewPatchJob::dispatch($ticket, $patch, $checksPass)
                        ->delay(now()->addSeconds(5));
                }
                break;

            case Workflow::STATE_REVIEWING:
            case Workflow::STATE_FIXING:
                // Create PR (handled by ReviewPatchJob or FixIterationJob)
                break;

            case Workflow::STATE_PR_CREATED:
                // Mark as done
                $this->transitionTo($workflow, Workflow::STATE_DONE);
                break;

            case Workflow::STATE_DONE:
                Log::info('Workflow completed', [
                    'workflow_id' => $workflow->id,
                ]);
                break;

            case Workflow::STATE_FAILED:
                Log::warning('Workflow in failed state', [
                    'workflow_id' => $workflow->id,
                ]);
                break;

            default:
                Log::error('Unknown workflow state', [
                    'workflow_id' => $workflow->id,
                    'state' => $workflow->state,
                ]);
        }
    }

    /**
     * Mark workflow as failed.
     */
    public function markAsFailed(Workflow $workflow, string $reason): void
    {
        Log::error('Marking workflow as failed', [
            'workflow_id' => $workflow->id,
            'reason' => $reason,
        ]);

        $workflow->update([
            'state' => Workflow::STATE_FAILED,
            'meta' => array_merge($workflow->meta ?? [], [
                'failure_reason' => $reason,
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Retry a failed workflow.
     */
    public function retryWorkflow(Workflow $workflow): void
    {
        if ($workflow->state !== Workflow::STATE_FAILED) {
            throw new \Exception('Can only retry failed workflows');
        }

        $maxRetries = config('synaptic.policies.retries.max_validation_retries', 3);

        if ($workflow->retries >= $maxRetries) {
            throw new \Exception('Maximum retries exceeded');
        }

        Log::info('Retrying workflow', [
            'workflow_id' => $workflow->id,
            'retry_count' => $workflow->retries + 1,
        ]);

        $workflow->update([
            'state' => Workflow::STATE_INGESTED,
            'retries' => $workflow->retries + 1,
            'meta' => array_merge($workflow->meta ?? [], [
                'retried_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->dispatchNextJob($workflow);
    }

    /**
     * Get workflow status summary.
     */
    public function getStatus(Workflow $workflow): array
    {
        $ticket = $workflow->ticket;

        return [
            'workflow_id' => $workflow->id,
            'ticket_id' => $ticket->id,
            'external_key' => $ticket->external_key,
            'current_state' => $workflow->state,
            'is_complete' => $workflow->state === Workflow::STATE_DONE,
            'is_failed' => $workflow->state === Workflow::STATE_FAILED,
            'retries' => $workflow->retries,
            'has_plan' => $ticket->plan !== null,
            'has_patch' => $ticket->patches()->exists(),
            'has_pr' => $ticket->pullRequests()->exists(),
            'created_at' => $workflow->created_at->toIso8601String(),
            'updated_at' => $workflow->updated_at->toIso8601String(),
            'duration_minutes' => $workflow->created_at->diffInMinutes($workflow->updated_at),
            'next_possible_states' => self::STATE_TRANSITIONS[$workflow->state] ?? [],
            'metadata' => $workflow->meta,
        ];
    }

    /**
     * Check if all mandatory checks passed.
     */
    private function checksPass(Ticket $ticket): bool
    {
        $runs = $ticket->runs()->latest()->get();
        $mandatoryChecks = config('synaptic.policies.mandatory_checks', []);

        foreach ($mandatoryChecks as $check => $required) {
            if ($required) {
                $run = $runs->firstWhere('type', $check);
                if (! $run || $run->status !== 'passed') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Cancel a workflow.
     */
    public function cancelWorkflow(Workflow $workflow): void
    {
        if (in_array($workflow->state, [Workflow::STATE_DONE, Workflow::STATE_FAILED])) {
            throw new \Exception('Cannot cancel completed or failed workflow');
        }

        Log::info('Cancelling workflow', [
            'workflow_id' => $workflow->id,
            'state' => $workflow->state,
        ]);

        $workflow->update([
            'state' => Workflow::STATE_FAILED,
            'meta' => array_merge($workflow->meta ?? [], [
                'cancelled' => true,
                'cancelled_at' => now()->toIso8601String(),
                'cancelled_from_state' => $workflow->state,
            ]),
        ]);
    }

    /**
     * Get workflow statistics.
     */
    public function getStatistics(): array
    {
        $stats = Workflow::selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state')
            ->toArray();

        $totalWorkflows = array_sum($stats);
        $completedWorkflows = $stats[Workflow::STATE_DONE] ?? 0;
        $failedWorkflows = $stats[Workflow::STATE_FAILED] ?? 0;
        $inProgressWorkflows = $totalWorkflows - $completedWorkflows - $failedWorkflows;

        // Calculate average duration for completed workflows
        $avgDuration = Workflow::where('state', Workflow::STATE_DONE)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_minutes')
            ->value('avg_minutes') ?? 0;

        return [
            'total_workflows' => $totalWorkflows,
            'completed' => $completedWorkflows,
            'failed' => $failedWorkflows,
            'in_progress' => $inProgressWorkflows,
            'success_rate' => $totalWorkflows > 0
                ? round(($completedWorkflows / $totalWorkflows) * 100, 2)
                : 0,
            'average_duration_minutes' => round($avgDuration, 2),
            'by_state' => $stats,
        ];
    }
}
