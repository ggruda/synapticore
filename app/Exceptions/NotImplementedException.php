<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class NotImplementedException extends RuntimeException
{
    public function __construct(string $message = 'This method is not yet implemented')
    {
        parent::__construct($message);
    }
}
