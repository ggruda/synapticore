<?php

declare(strict_types=1);

namespace App\Services\Vcs;

use App\Contracts\VcsProviderContract;
use App\DTO\OpenPrDto;
use App\DTO\PrCreatedDto;
use App\Exceptions\NotImplementedException;

/**
 * Bitbucket skeleton implementation of the VCS provider contract.
 */
class BitbucketVcsProvider implements VcsProviderContract
{
    public function __construct(
        private readonly string $workspace,
        private readonly string $username,
        private readonly string $appPassword,
    ) {
        // Constructor dependency injection for required config
    }

    /**
     * {@inheritDoc}
     */
    public function clone(string $repoUrl, string $dest): void
    {
        throw new NotImplementedException('BitbucketVcsProvider::clone() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function createBranch(string $dest, string $branch, string $base): void
    {
        throw new NotImplementedException('BitbucketVcsProvider::createBranch() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function commitAll(string $dest, string $message): void
    {
        throw new NotImplementedException('BitbucketVcsProvider::commitAll() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function push(string $dest, string $branch): void
    {
        throw new NotImplementedException('BitbucketVcsProvider::push() not yet implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function openPr(OpenPrDto $dto): PrCreatedDto
    {
        throw new NotImplementedException('BitbucketVcsProvider::openPr() not yet implemented');
    }
}
