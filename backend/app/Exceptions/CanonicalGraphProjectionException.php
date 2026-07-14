<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class CanonicalGraphProjectionException extends RuntimeException
{
    public readonly string $failureCode;

    public function __construct(
        public readonly string $projectionId,
        string $failureCode,
        ?Throwable $previous = null,
    ) {
        $this->failureCode = preg_match('/\A[a-z0-9_]{1,100}\z/', $failureCode) === 1
            ? $failureCode
            : 'projection_failed';
        parent::__construct($this->failureCode, 0, $previous);
    }
}
