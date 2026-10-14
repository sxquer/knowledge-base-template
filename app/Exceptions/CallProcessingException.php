<?php

namespace App\Exceptions;

use RuntimeException;

class CallProcessingException extends RuntimeException
{
    public function __construct(string $message, public bool $retryable = true)
    {
        parent::__construct($message);
    }
}
