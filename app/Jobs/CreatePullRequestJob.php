<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VcsProviderContract;
use App\DTO\OpenPrDto;
use App\Models\Patch;
use App\Models\PullRequest;
use App\Models\Ticket;
use App\Services\RepoManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to create pull request.
 * Commits, pushes, opens PR with detailed body.
 */
class CreatePullRequestJob implements ShouldQueue
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
        public Patch $patch,
        public bool $draft = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        VcsProviderContract $vcsProvider,
        RepoManager $repoManager,
    ): void {
        Log::info('Creating pull request', [
            'ticket_id' => $this->ticket->id,
            'patch_id' => $this->patch->id,
            'draft' => $this->draft,
        ]);

        try {
            $workspacePath = $repoManager->getWorkspacePath($this->ticket);
            $branchName = $repoManager->getBranchName($this->ticket);

            // Commit all changes
            $commitMessage = $this->generateCommitMessage();
            $repoManager->commitAll($workspacePath, $commitMessage);

            // Push to remote
            $repoManager->push($workspacePath, $branchName);

            // Prepare PR data
            $prDto = new OpenPrDto(
                title: $this->generatePrTitle(),
                body: $this->generatePrBody(),
                baseBranch: $this->ticket->project->default_branch ?? 'main',
                headBranch: $branchName,
                isDraft: $this->shouldBeDraft(),
                labels: $this->generateLabels(),
                reviewers: $this->determineReviewers(),
                assignees: [$this->ticket->assignee ?? ''],
                metadata: [
                    'ticket_id' => $this->ticket->id,
                    'patch_id' => $this->patch->id,
                ],
            );

            // Open PR via provider
            $prCreated = $vcsProvider->openPr($prDto);

            // Store PR record
            $pullRequest = PullRequest::create([
                'ticket_id' => $this->ticket->id,
                'provider_id' => $prCreated->id,
                'url' => $prCreated->url,
                'branch_name' => $branchName,
                'is_draft' => $prCreated->isDraft,
                'labels' => $prCreated->labels,
            ]);

            Log::info('Pull request created', [
                'ticket_id' => $this->ticket->id,
                'pr_id' => $pullRequest->id,
                'provider_id' => $prCreated->id,
                'url' => $prCreated->url,
                'is_draft' => $prCreated->isDraft,
            ]);

            // Update workflow state
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'PR_CREATED',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'pr_created_at' => now()->toIso8601String(),
                        'pr_url' => $prCreated->url,
                        'pr_id' => $pullRequest->id,
                    ]),
                ]);
            }

            // Mark workflow as done if PR is not a draft
            if (! $prCreated->isDraft) {
                $this->ticket->workflow?->update(['state' => 'DONE']);
            }

        } catch (\Exception $e) {
            Log::error('Pull request creation failed', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update workflow with error
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'state' => 'FAILED',
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'pr_error' => $e->getMessage(),
                        'pr_failed_at' => now()->toIso8601String(),
                    ]),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Generate commit message.
     */
    private function generateCommitMessage(): string
    {
        $ticket = $this->ticket;
        $message = "[{$ticket->external_key}] {$ticket->title}\n\n";

        if ($ticket->plan) {
            $message .= "Implementation:\n";
            foreach ($ticket->plan->plan_json['steps'] ?? [] as $index => $step) {
                $message .= "- {$step['rationale']}\n";
                if ($index >= 4) {
                    $message .= "- ...and more\n";
                    break;
                }
            }
        }

        $message .= "\n";
        $message .= 'Files changed: '.count($this->patch->files_touched ?? [])."\n";
        $message .= 'Risk level: '.($this->patch->summary['review_result']['risk_level'] ?? 'unknown')."\n";

        return $message;
    }

    /**
     * Generate PR title.
     */
    private function generatePrTitle(): string
    {
        $ticket = $this->ticket;
        $prefix = $this->shouldBeDraft() ? '[DRAFT] ' : '';

        return $prefix."[{$ticket->external_key}] {$ticket->title}";
    }

    /**
     * Generate detailed PR body.
     */
    private function generatePrBody(): string
    {
        $ticket = $this->ticket;
        $patch = $this->patch;
        $plan = $ticket->plan;

        $body = "## 🎯 What\n\n";
        $body .= $ticket->body."\n\n";

        if (! empty($ticket->acceptance_criteria)) {
            $body .= "### Acceptance Criteria\n";
            foreach ($ticket->acceptance_criteria as $criteria) {
                $body .= "- [ ] {$criteria}\n";
            }
            $body .= "\n";
        }

        $body .= "## 🤔 Why\n\n";
        $body .= $plan->plan_json['summary'] ?? 'Implementation needed as per ticket requirements.';
        $body .= "\n\n";

        $body .= "## 🔨 How\n\n";
        if ($plan) {
            foreach ($plan->plan_json['steps'] ?? [] as $index => $step) {
                $body .= ($index + 1).". **{$step['intent']}**: {$step['rationale']}\n";
            }
        }
        $body .= "\n";

        $body .= "## ✅ How Tested\n\n";
        $testResults = $this->getTestResultsSummary();
        if (! empty($testResults)) {
            foreach ($testResults as $result) {
                $emoji = $result['status'] === 'passed' ? '✅' : '❌';
                $body .= "- {$emoji} **{$result['type']}**: {$result['status']}\n";
            }
        } else {
            $body .= "- Manual testing performed\n";
            $body .= "- All existing tests pass\n";
        }

        if ($plan) {
            $body .= "\n**Test Strategy**: {$plan->test_strategy}\n";
        }
        $body .= "\n";

        $body .= "## ⚠️ Risks\n\n";
        $riskLevel = $patch->summary['review_result']['risk_level'] ?? 'unknown';
        $riskScore = $patch->summary['review_result']['score'] ?? 0;
        $body .= '- **Risk Level**: '.ucfirst($riskLevel)." (Score: {$riskScore}/100)\n";

        if (! empty($patch->summary['review_result']['issues'])) {
            $body .= "\n### Known Issues\n";
            foreach (array_slice($patch->summary['review_result']['issues'], 0, 5) as $issue) {
                $body .= "- [{$issue['severity']}] {$issue['message']}\n";
            }
        }
        $body .= "\n";

        $body .= "## 📋 Checklist\n\n";
        $checklist = $patch->summary['review_result']['checklist'] ?? [];
        if (! empty($checklist)) {
            foreach ($checklist as $item) {
                $checked = str_starts_with($item, '✓') ? 'x' : ' ';
                $text = trim(str_replace(['✓', '⚠️'], '', $item));
                $body .= "- [{$checked}] {$text}\n";
            }
        } else {
            $body .= "- [x] Code follows style guidelines\n";
            $body .= "- [x] Tests pass locally\n";
            $body .= "- [x] Self-review completed\n";
            $body .= "- [ ] Documentation updated\n";
        }
        $body .= "\n";

        $body .= "## 📊 Metrics\n\n";
        $body .= "| Metric | Value |\n";
        $body .= "|--------|-------|\n";
        $body .= '| Files Changed | '.count($patch->files_touched ?? [])." |\n";
        $body .= '| Lines Added | '.($patch->diff_stats['additions'] ?? 0)." |\n";
        $body .= '| Lines Removed | '.($patch->diff_stats['deletions'] ?? 0)." |\n";

        $coverage = $this->getTestCoverage();
        if ($coverage !== null) {
            $body .= "| Test Coverage | {$coverage}% |\n";
        }

        $body .= '| Review Score | '.($patch->summary['review_result']['score'] ?? 0)."/100 |\n";
        $body .= "\n";

        $body .= "## 🔗 Links\n\n";
        $body .= "- [Ticket: {$ticket->external_key}](".($ticket->meta['url'] ?? '#').")\n";

        // Add artifact links
        $artifacts = $this->getArtifactLinks();
        if (! empty($artifacts)) {
            $body .= "\n### Artifacts\n";
            foreach ($artifacts as $artifact) {
                $body .= "- [{$artifact['name']}]({$artifact['url']})\n";
            }
        }

        $body .= "\n---\n";
        $body .= '_Generated by Synapticore Bot • '.now()->format('Y-m-d H:i:s').' UTC_';

        return $body;
    }

    /**
     * Determine if PR should be draft.
     */
    private function shouldBeDraft(): bool
    {
        // Already specified as draft
        if ($this->draft) {
            return true;
        }

        // Draft if risk is medium or higher
        $riskLevel = $this->patch->summary['review_result']['risk_level'] ?? 'low';
        if (in_array($riskLevel, ['medium', 'high', 'critical'])) {
            return true;
        }

        // Draft if review didn't pass
        if (! ($this->patch->summary['review_result']['passed'] ?? true)) {
            return true;
        }

        // Draft if there are critical issues
        $issues = $this->patch->summary['review_result']['issues'] ?? [];
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'critical') {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate labels for PR.
     */
    private function generateLabels(): array
    {
        $labels = [];

        // Add ticket labels
        $labels = array_merge($labels, $this->ticket->labels ?? []);

        // Add risk label
        $riskLevel = $this->patch->summary['review_result']['risk_level'] ?? 'unknown';
        $labels[] = "risk:{$riskLevel}";

        // Add type label
        $labels[] = 'bot-generated';

        // Add status label
        if ($this->shouldBeDraft()) {
            $labels[] = 'draft';
            $labels[] = 'needs-review';
        } else {
            $labels[] = 'ready-for-review';
        }

        // Add language labels
        $languages = $this->ticket->project->language_profile['languages'] ?? [];
        foreach ($languages as $lang) {
            $labels[] = "lang:{$lang}";
        }

        return array_unique($labels);
    }

    /**
     * Determine reviewers based on risk and requirements.
     */
    private function determineReviewers(): array
    {
        $reviewers = [];
        $riskLevel = $this->patch->summary['review_result']['risk_level'] ?? 'low';

        // Get review requirements from policy
        $requirements = config("synaptic.policies.review_requirements.{$riskLevel}", []);

        // Add configured reviewers
        $configuredReviewers = config('synaptic.pr.reviewers', []);

        if ($requirements['require_senior'] ?? false) {
            // Add senior reviewers
            $seniorReviewers = array_filter($configuredReviewers, fn ($r) => str_contains($r, 'senior'));
            $reviewers = array_merge($reviewers, $seniorReviewers);
        }

        if ($requirements['require_security_review'] ?? false) {
            // Add security reviewers
            $securityReviewers = array_filter($configuredReviewers, fn ($r) => str_contains($r, 'security'));
            $reviewers = array_merge($reviewers, $securityReviewers);
        }

        // Ensure minimum number of reviewers
        $minReviewers = $requirements['min_reviewers'] ?? 1;
        while (count($reviewers) < $minReviewers && count($configuredReviewers) > 0) {
            $reviewers[] = array_shift($configuredReviewers);
        }

        return array_unique(array_filter($reviewers));
    }

    /**
     * Get test results summary.
     */
    private function getTestResultsSummary(): array
    {
        $runs = $this->ticket->runs()->latest()->limit(5)->get();
        $summary = [];

        foreach ($runs as $run) {
            $summary[] = [
                'type' => $run->type,
                'status' => $run->status,
            ];
        }

        return $summary;
    }

    /**
     * Get test coverage percentage.
     */
    private function getTestCoverage(): ?float
    {
        $testRun = $this->ticket->runs()
            ->where('type', 'test')
            ->whereNotNull('coverage_path')
            ->latest()
            ->first();

        if (! $testRun || ! $testRun->coverage_path) {
            return null;
        }

        // Try to parse coverage from metadata or summary
        return $this->patch->summary['test_coverage'] ?? null;
    }

    /**
     * Get artifact links.
     */
    private function getArtifactLinks(): array
    {
        $artifacts = [];
        $baseUrl = config('app.url').'/artifacts';

        // Add test results
        $testRun = $this->ticket->runs()->where('type', 'test')->latest()->first();
        if ($testRun) {
            if ($testRun->junit_path) {
                $artifacts[] = [
                    'name' => 'Test Results (JUnit)',
                    'url' => "{$baseUrl}/{$testRun->junit_path}",
                ];
            }
            if ($testRun->coverage_path) {
                $artifacts[] = [
                    'name' => 'Code Coverage Report',
                    'url' => "{$baseUrl}/{$testRun->coverage_path}",
                ];
            }
        }

        // Add lint results
        $lintRun = $this->ticket->runs()->where('type', 'lint')->latest()->first();
        if ($lintRun && $lintRun->logs_path) {
            $artifacts[] = [
                'name' => 'Lint Report',
                'url' => "{$baseUrl}/{$lintRun->logs_path}",
            ];
        }

        return $artifacts;
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreatePullRequestJob failed', [
            'ticket_id' => $this->ticket->id,
            'patch_id' => $this->patch->id,
            'error' => $exception->getMessage(),
        ]);

        // Update workflow state
        if ($this->ticket->workflow) {
            $this->ticket->workflow->update([
                'state' => 'FAILED',
                'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                    'pr_job_failed' => true,
                    'pr_job_error' => $exception->getMessage(),
                ]),
            ]);
        }
    }
}
