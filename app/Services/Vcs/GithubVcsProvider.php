<?php

declare(strict_types=1);

namespace App\Services\Vcs;

use App\Contracts\VcsProviderContract;
use App\DTO\OpenPrDto;
use App\DTO\PrCreatedDto;
use App\Exceptions\NotImplementedException;

/**
 * GitHub skeleton implementation of the VCS provider contract.
 */
class GithubVcsProvider implements VcsProviderContract
{
    public function __construct(
        private readonly string $token,
        private readonly ?string $organization = null,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function clone(string $repoUrl, string $dest): void
    {
        // TODO: Implement repository cloning
        throw new NotImplementedException('GithubVcsProvider::clone() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function createBranch(string $dest, string $branch, string $base): void
    {
        // TODO: Implement branch creation
        throw new NotImplementedException('GithubVcsProvider::createBranch() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function commitAll(string $dest, string $message): void
    {
        // TODO: Implement commit functionality
        throw new NotImplementedException('GithubVcsProvider::commitAll() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function push(string $dest, string $branch): void
    {
        // TODO: Implement push functionality
        throw new NotImplementedException('GithubVcsProvider::push() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function openPr(OpenPrDto $dto): PrCreatedDto
    {
        // TODO: Implement PR creation via GitHub API
        throw new NotImplementedException('GithubVcsProvider::openPr() not yet implemented');
    }
}
