<?php

namespace App\Services\Hades;

use RuntimeException;

class HadesJobException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
