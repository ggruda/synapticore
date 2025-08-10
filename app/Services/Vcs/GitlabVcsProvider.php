<?php

declare(strict_types=1);

namespace App\Services\Vcs;

use App\Contracts\VcsProviderContract;
use App\DTO\OpenPrDto;
use App\DTO\PrCreatedDto;
use App\Exceptions\NotImplementedException;

/**
 * GitLab skeleton implementation of the VCS provider contract.
 */
class GitlabVcsProvider implements VcsProviderContract
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function clone(string $repoUrl, string $dest): void
    {
        throw new NotImplementedException('GitlabVcsProvider::clone() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function createBranch(string $dest, string $branch, string $base): void
    {
        throw new NotImplementedException('GitlabVcsProvider::createBranch() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function commitAll(string $dest, string $message): void
    {
        throw new NotImplementedException('GitlabVcsProvider::commitAll() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function push(string $dest, string $branch): void
    {
        throw new NotImplementedException('GitlabVcsProvider::push() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function openPr(OpenPrDto $dto): PrCreatedDto
    {
        throw new NotImplementedException('GitlabVcsProvider::openPr() not yet implemented');
    }
}
