<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\OpenPrDto;
use App\DTO\PrCreatedDto;

/**
 * Contract for version control system providers (GitHub, GitLab, Bitbucket, etc.).
 */
interface VcsProviderContract
{
    /**
     * Clone a repository to a local destination.
     *
     * @param  string  $repoUrl  The repository URL
     * @param  string  $dest  The destination directory path
     *
     * @throws \App\Exceptions\RepositoryNotFoundException
     * @throws \App\Exceptions\CloneFailedException
     */
    public function clone(string $repoUrl, string $dest): void;

    /**
     * Create a new branch in the repository.
     *
     * @param  string  $dest  The repository directory path
     * @param  string  $branch  The new branch name
     * @param  string  $base  The base branch to branch from
     *
     * @throws \App\Exceptions\BranchCreationFailedException
     * @throws \App\Exceptions\RepositoryNotFoundException
     */
    public function createBranch(string $dest, string $branch, string $base): void;

    /**
     * Commit all changes in the repository.
     *
     * @param  string  $dest  The repository directory path
     * @param  string  $message  The commit message
     *
     * @throws \App\Exceptions\CommitFailedException
     * @throws \App\Exceptions\NothingToCommitException
     */
    public function commitAll(string $dest, string $message): void;

    /**
     * Push changes to the remote repository.
     *
     * @param  string  $dest  The repository directory path
     * @param  string  $branch  The branch to push
     *
     * @throws \App\Exceptions\PushFailedException
     * @throws \App\Exceptions\AuthenticationFailedException
     */
    public function push(string $dest, string $branch): void;

    /**
     * Open a pull request in the version control system.
     *
     * @param  OpenPrDto  $dto  The pull request details
     * @return PrCreatedDto The created pull request information
     *
     * @throws \App\Exceptions\PullRequestCreationFailedException
     * @throws \App\Exceptions\AuthenticationFailedException
     */
    public function openPr(OpenPrDto $dto): PrCreatedDto;
}
