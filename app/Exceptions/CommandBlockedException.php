<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when a command is blocked for security reasons.
 */
class CommandBlockedException extends SynapticException
{
    /**
     * Create a new command blocked exception.
     */
    public function __construct(string $message = 'Command execution blocked for security reasons')
    {
        parent::__construct($message);
    }
}