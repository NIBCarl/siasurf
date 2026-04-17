<?php

namespace App\Exceptions;

use Exception;

class SafetyViolationException extends Exception
{
    public function __construct(string $message = 'Safety requirement not met', int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
