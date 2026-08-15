<?php

namespace App\Exceptions;

use Exception;

class ConflictException extends Exception
{
    protected $message = 'Conflict occurred';

    /** @var int */
    protected $code = 409;

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? $this->message, $this->code);
    }
}
