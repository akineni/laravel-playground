<?php

namespace App\Exceptions;

use Exception;

class InvalidUploadSignatureException extends Exception
{
    protected $message = 'The upload could not be verified. It may have failed or been tampered with.';

    /** @var int */
    protected $code = 422;

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? $this->message, $this->code);
    }
}
