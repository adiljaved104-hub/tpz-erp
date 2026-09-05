<?php

namespace App\Exceptions;

use RuntimeException;

class HikvisionTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}
