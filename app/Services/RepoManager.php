<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\VcsProviderContract;
use App\Exceptions\CloneFailedException;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages repository operations: clone, branch, enforce paths.
 * Works with VcsProviderContract for provider-specific operations.
 */
class RepoManager
{
    /**
     * Base path for workspaces.
     */
    private const WORKSPACE_BASE = 'workspaces';

    public function __construct(
        private readonly VcsProviderContract $vcsProvider,
    ) {}

    /**
     * Clone repository for a ticket and create feature branch.
     *
     * @return string Path to the cloned repository
     *
     * @throws CloneFailedException
     */
    public function setupWorkspace(Ticket $ticket): string
    {
        $project = $ticket->project;
        $workspacePath = $this->getWorkspacePath($ticket);

        // Clean up existing workspace
        $this->cleanWorkspace($ticket);

        // Create workspace directory
        Storage::makeDirectory($this->getWorkspaceStoragePath($ticket));

        try {
            // Get repository URL
            $repoUrl = $this->getRepositoryUrl($project);

            // Clone repository (shallow clone for performance)
            $this->cloneRepository($repoUrl, $workspacePath, $project->default_branch);

            // Create feature branch
            $branchName = $this->createBranchName($ticket);
            $this->createBranch($workspacePath, $branchName, $project->default_branch);

            // Apply path restrictions
            $this->applyPathRestrictions($workspacePath, $project);

            Log::info('Workspace setup completed', [
                'ticket_id' => $ticket->id,
                'workspace' => $workspacePath,
                'branch' => $branchName,
            ]);

            return $workspacePath;
        } catch (\Exception $e) {
            // Clean up on failure
            $this->cleanWorkspace($ticket);

            Log::error('Failed to setup workspace', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            throw new CloneFailedException(
                "Failed to setup workspace for ticket {$ticket->external_key}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Clean up workspace for a ticket.
     */
    public function cleanWorkspace(Ticket $ticket): void
    {
        $path = $this->getWorkspaceStoragePath($ticket);

        if (Storage::exists($path)) {
            Storage::deleteDirectory($path);
            Log::info('Cleaned workspace', ['ticket_id' => $ticket->id]);
        }
    }

    /**
     * Get workspace path for a ticket.
     */
    public function getWorkspacePath(Ticket $ticket): string
    {
        return Storage::path($this->getWorkspaceStoragePath($ticket).'/repo');
    }

    /**
     * Get workspace storage path relative to storage/app.
     */
    private function getWorkspaceStoragePath(Ticket $ticket): string
    {
        return self::WORKSPACE_BASE.'/'.$ticket->id;
    }

    /**
     * Get repository URL from project.
     */
    private function getRepositoryUrl(Project $project): string
    {
        $urls = $project->repo_urls;

        if (empty($urls)) {
            throw new \InvalidArgumentException('No repository URLs configured for project');
        }

        // Use first URL
        return $urls[0];
    }

    /**
     * Clone repository using git.
     */
    private function cloneRepository(string $url, string $destination, string $branch): void
    {
        // Prepare clone command with shallow clone
        $command = [
            'git',
            'clone',
            '--depth=1',
            '--branch='.escapeshellarg($branch),
            $url,
            $destination,
        ];

        $result = Process::timeout(300)->run(implode(' ', $command));

        if (! $result->successful()) {
            throw new CloneFailedException(
                "Git clone failed: {$result->errorOutput()}"
            );
        }

        Log::info('Repository cloned', [
            'url' => $url,
            'branch' => $branch,
            'destination' => $destination,
        ]);
    }

    /**
     * Create and checkout feature branch.
     */
    private function createBranch(string $repoPath, string $branchName, string $baseBranch): void
    {
        // Create new branch
        $result = Process::path($repoPath)
            ->run("git checkout -b {$branchName}");

        if (! $result->successful()) {
            throw new \RuntimeException(
                "Failed to create branch {$branchName}: {$result->errorOutput()}"
            );
        }

        Log::info('Created feature branch', [
            'branch' => $branchName,
            'base' => $baseBranch,
        ]);
    }

    /**
     * Create branch name from ticket.
     * Format: sc/{TICKET-KEY}/{slug}
     */
    private function createBranchName(Ticket $ticket): string
    {
        $slug = Str::slug(
            Str::limit($ticket->title, 50, ''),
            '-'
        );

        return "sc/{$ticket->external_key}/{$slug}";
    }

    /**
     * Apply path restrictions to repository.
     * Creates .gitignore-like restrictions.
     */
    private function applyPathRestrictions(string $repoPath, Project $project): void
    {
        $allowedPaths = $project->allowed_paths ?? [];

        if (empty($allowedPaths)) {
            // No restrictions
            return;
        }

        // Create .synapticore-allowed file
        $allowedFile = $repoPath.'/.synapticore-allowed';
        file_put_contents($allowedFile, implode("\n", $allowedPaths));

        Log::info('Applied path restrictions', [
            'allowed_paths' => $allowedPaths,
        ]);
    }

    /**
     * Check if a file path is allowed for modification.
     */
    public function isPathAllowed(string $filePath, Project $project): bool
    {
        $allowedPaths = $project->allowed_paths ?? [];

        if (empty($allowedPaths)) {
            // No restrictions, all paths allowed
            return true;
        }

        // Normalize the file path
        $filePath = ltrim($filePath, '/');

        foreach ($allowedPaths as $pattern) {
            // Handle wildcard patterns
            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace(
                    ['/', '*'],
                    ['\/', '.*'],
                    $pattern
                ).'$/';

                if (preg_match($regex, $filePath)) {
                    return true;
                }
            } elseif (str_ends_with($pattern, '/')) {
                // Directory pattern
                if (str_starts_with($filePath, $pattern)) {
                    return true;
                }
            } else {
                // Exact file match
                if ($filePath === $pattern) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get modified files in workspace.
     *
     * @return array<string>
     */
    public function getModifiedFiles(string $workspacePath): array
    {
        $result = Process::path($workspacePath)
            ->run('git diff --name-only HEAD');

        if (! $result->successful()) {
            return [];
        }

        $files = array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn ($file) => ! empty($file)
        );

        return array_values($files);
    }

    /**
     * Stage and commit changes.
     */
    public function commitChanges(string $workspacePath, string $message): void
    {
        // Stage all changes
        $result = Process::path($workspacePath)->run('git add -A');

        if (! $result->successful()) {
            throw new \RuntimeException("Failed to stage changes: {$result->errorOutput()}");
        }

        // Commit
        $result = Process::path($workspacePath)
            ->run(['git', 'commit', '-m', $message]);

        if (! $result->successful()) {
            throw new \RuntimeException("Failed to commit: {$result->errorOutput()}");
        }

        Log::info('Committed changes', [
            'workspace' => $workspacePath,
            'message' => $message,
        ]);
    }

    /**
     * Push branch to remote.
     */
    public function pushBranch(string $workspacePath, string $branchName): void
    {
        $result = Process::path($workspacePath)
            ->timeout(120)
            ->run("git push origin {$branchName}");

        if (! $result->successful()) {
            throw new \RuntimeException("Failed to push branch: {$result->errorOutput()}");
        }

        Log::info('Pushed branch', [
            'workspace' => $workspacePath,
            'branch' => $branchName,
        ]);
    }
}
