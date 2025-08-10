<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when a path access violation is detected.
 */
class PathViolationException extends SynapticException
{
    /**
     * Create a new path violation exception.
     */
    public function __construct(string $message = 'Path access violation detected')
    {
        parent::__construct($message);
    }
}