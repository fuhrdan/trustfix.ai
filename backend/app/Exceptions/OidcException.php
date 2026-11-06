<?php

namespace App\Exceptions;

use RuntimeException;

class OidcException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message, $status);
    }
}
