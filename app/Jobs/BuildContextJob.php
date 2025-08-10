<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\Context\EmbeddingIndexer;
use App\Services\RepoManager;
use App\Services\RepoProfiler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to build context for a ticket.
 * Orchestrates: checkout → profile → index embeddings for allowed_paths.
 */
class BuildContextJob implements ShouldQueue
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
    public $timeout = 600;

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
        RepoManager $repoManager,
        RepoProfiler $repoProfiler,
        EmbeddingIndexer $embeddingIndexer,
    ): void {
        Log::info('Building context for ticket', [
            'ticket_id' => $this->ticket->id,
            'external_key' => $this->ticket->external_key,
        ]);

        try {
            // Step 1: Setup workspace (clone repository)
            $workspacePath = $repoManager->setupWorkspace($this->ticket);

            Log::info('Workspace setup completed', [
                'ticket_id' => $this->ticket->id,
                'workspace' => $workspacePath,
            ]);

            // Step 2: Profile repository
            $profile = $repoProfiler->profileRepository($workspacePath);

            Log::info('Repository profiled', [
                'ticket_id' => $this->ticket->id,
                'languages' => $profile->languages,
                'frameworks' => array_keys($profile->frameworks),
                'tools' => $profile->tools,
            ]);

            // Save profile to project
            $project = $this->ticket->project;
            $project->update([
                'language_profile' => $profile->toArray(),
            ]);

            // Step 3: Index embeddings for allowed paths
            $allowedPaths = $project->allowed_paths ?? [];

            // Clear existing embeddings for this project
            $embeddingIndexer->clearProjectEmbeddings($project->id);

            // Index the repository
            $chunksIndexed = $embeddingIndexer->indexRepository(
                repoPath: $workspacePath,
                projectId: $project->id,
                allowedPaths: $allowedPaths,
            );

            Log::info('Repository indexed with embeddings', [
                'ticket_id' => $this->ticket->id,
                'project_id' => $project->id,
                'chunks_indexed' => $chunksIndexed,
            ]);

            // Update ticket metadata
            $this->ticket->update([
                'meta' => array_merge($this->ticket->meta ?? [], [
                    'context_built' => true,
                    'context_built_at' => now()->toIso8601String(),
                    'chunks_indexed' => $chunksIndexed,
                    'profile' => [
                        'languages' => $profile->languages,
                        'frameworks' => array_keys($profile->frameworks),
                    ],
                ]),
            ]);

            // Update workflow if exists
            if ($this->ticket->workflow) {
                $this->ticket->workflow->update([
                    'meta' => array_merge($this->ticket->workflow->meta ?? [], [
                        'context_ready' => true,
                        'workspace_path' => $workspacePath,
                    ]),
                ]);
            }

            Log::info('Context building completed successfully', [
                'ticket_id' => $this->ticket->id,
                'chunks_indexed' => $chunksIndexed,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to build context', [
                'ticket_id' => $this->ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update ticket with error
            $this->ticket->update([
                'meta' => array_merge($this->ticket->meta ?? [], [
                    'context_error' => $e->getMessage(),
                    'context_failed_at' => now()->toIso8601String(),
                ]),
            ]);

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('BuildContextJob failed', [
            'ticket_id' => $this->ticket->id,
            'error' => $exception->getMessage(),
        ]);

        // Update ticket with failure
        $this->ticket->update([
            'meta' => array_merge($this->ticket->meta ?? [], [
                'context_job_failed' => true,
                'context_job_error' => $exception->getMessage(),
                'context_job_failed_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
