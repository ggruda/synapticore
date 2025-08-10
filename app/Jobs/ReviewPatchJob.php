<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiReviewerContract;
use App\DTO\ReviewInputDto;
use App\Models\Patch;
use App\Models\Ticket;
use App\Services\Validation\PolicyEnforcer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to review patch with guardrails and AI.
 * If failing, enqueues FixIterationJob with bounded retries.
 */
class ReviewPatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

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
        public Patch $patch,
        public bool $checksPass = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AiReviewerContract $reviewer,
        PolicyEnforcer $policyEnforcer,
    ): void {
        Log::info('Starting patch review', [
            'ticket_id' => $this->ticket->id,
            'patch_id' => $this->patch->id,
            'checks_pass' => $this->checksPass,
        ]);

        try {
            // Apply policy guardrails first
            $patchSummary = $this->patch->toPatchSummaryJson();
            $policyResult = $policyEnforcer->checkPatchCompliance($patchSummary);

            Log::info('Policy check completed', [
                'ticket_id' => $this->ticket->id,
                'passed' => $policyResult->passed,
                'risk_score' => $policyResult->riskScore,
                'risk_level' => $policyResult->riskLevel,
                'violations' => count($policyResult->violations),
            ]);

            // Prepare review input
            $input = new ReviewInputDto(
                patch: $patchSummary,
                testResults: $this->getTestResults(),
                checksPass: $this->checksPass,
                policyViolations: $policyResult->violations,
            );

            // Get AI review
            $reviewResult = $reviewer->review($input);

            // Merge policy and AI review results
            $finalReview = $this->mergeReviewResults($policyResult, $reviewResult);

            // Store review result
            $this->patch->update([
                'summary' => array_merge($this->patch->summary ?? [], [
                    'review_result' => $finalReview,
                    'review_completed_at' => now()->toIso8601String(),
                ]),
            ]);

            // Update workflow state
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'REVIEWING',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'review_completed_at' => now()->toIso8601String(),
                        'review_passed' => $finalReview['passed'],
                        'review_score' => $finalReview['score'],
                    ]),
                ]);
            }

            Log::info('Review completed', [
                'ticket_id' => $this->ticket->id,
                'passed' => $finalReview['passed'],
                'score' => $finalReview['score'],
                'issues' => count($finalReview['issues']),
            ]);

            // Decide next action based on review
            if ($finalReview['passed']) {
                // Review passed, create PR
                CreatePullRequestJob::dispatch($this->ticket, $this->patch)
                    ->delay(now()->addSeconds(5));
            } else {
                // Review failed, check if we should iterate
                $iterations = $this->ticket->workflow->meta['fix_iterations'] ?? 0;
                $maxIterations = config('synaptic.policies.retries.max_implementation_retries', 2);

                if ($iterations < $maxIterations && $finalReview['fixable']) {
                    // Dispatch fix iteration job
                    FixIterationJob::dispatch(
                        $this->ticket,
                        $this->patch,
                        $finalReview['issues']
                    )->delay(now()->addSeconds(10));

                    // Update iteration count
                    $this->ticket->workflow->update([
                        'state' => 'FIXING',
                        'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                            'fix_iterations' => $iterations + 1,
                        ]),
                    ]);

                    Log::info('Dispatching fix iteration', [
                        'ticket_id' => $this->ticket->id,
                        'iteration' => $iterations + 1,
                        'max_iterations' => $maxIterations,
                    ]);
                } else {
                    // Max iterations reached or not fixable, create PR anyway with warnings
                    Log::warning('Review failed but proceeding with PR', [
                        'ticket_id' => $this->ticket->id,
                        'reason' => $iterations >= $maxIterations ? 'max_iterations_reached' : 'not_fixable',
                    ]);

                    CreatePullRequestJob::dispatch($this->ticket, $this->patch, draft: true)
                        ->delay(now()->addSeconds(5));
                }
            }

        } catch (\Exception $e) {
            Log::error('Review failed', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update workflow with error
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'FAILED',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'review_error' => $e->getMessage(),
                        'review_failed_at' => now()->toIso8601String(),
                    ]),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Get test results from runs.
     */
    private function getTestResults(): array
    {
        $runs = $this->ticket->runs()->latest()->limit(10)->get();
        $results = [];

        foreach ($runs as $run) {
            $results[] = [
                'type' => $run->type,
                'status' => $run->status,
                'junit_path' => $run->junit_path,
                'coverage_path' => $run->coverage_path,
                'created_at' => $run->created_at->toIso8601String(),
            ];
        }

        return $results;
    }

    /**
     * Merge policy and AI review results.
     */
    private function mergeReviewResults($policyResult, $reviewResult): array
    {
        $allIssues = [];
        $allSuggestions = [];

        // Add policy violations as issues
        foreach ($policyResult->violations as $violation) {
            $allIssues[] = [
                'type' => 'policy',
                'severity' => 'high',
                'message' => $violation,
                'fixable' => true,
            ];
        }

        // Add AI review issues
        foreach ($reviewResult->issues as $issue) {
            $allIssues[] = array_merge($issue, ['type' => 'ai_review']);
        }

        // Add security issues
        foreach ($reviewResult->securityIssues as $issue) {
            $allIssues[] = array_merge($issue, ['type' => 'security']);
        }

        // Merge suggestions
        $allSuggestions = array_merge(
            $policyResult->warnings,
            $reviewResult->suggestions
        );

        // Calculate final score
        $policyScore = 100 - $policyResult->riskScore;
        $aiScore = $reviewResult->qualityScore;
        $finalScore = (int) (($policyScore + $aiScore) / 2);

        // Determine if passed
        $passed = $policyResult->passed && $reviewResult->isApproved() && $this->checksPass;

        // Check if issues are fixable
        $fixable = true;
        foreach ($allIssues as $issue) {
            if (($issue['severity'] ?? '') === 'critical' && ! ($issue['fixable'] ?? true)) {
                $fixable = false;
                break;
            }
        }

        return [
            'passed' => $passed,
            'score' => $finalScore,
            'status' => $passed ? 'approved' : 'needs_changes',
            'issues' => $allIssues,
            'suggestions' => $allSuggestions,
            'checklist' => $policyResult->reviewChecklist,
            'risk_level' => $policyResult->riskLevel,
            'fixable' => $fixable,
            'policy_passed' => $policyResult->passed,
            'ai_approved' => $reviewResult->isApproved(),
            'checks_passed' => $this->checksPass,
        ];
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ReviewPatchJob failed', [
            'ticket_id' => $this->ticket->id,
            'patch_id' => $this->patch->id,
            'error' => $exception->getMessage(),
        ]);

        // Update workflow state
        if ($this->ticket->workflow) {
            $this->ticket->workflow->update([
                'state' => 'FAILED',
                'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                    'review_job_failed' => true,
                    'review_job_error' => $exception->getMessage(),
                ]),
            ]);
        }
    }
}
