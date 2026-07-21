<?php

namespace App\Exceptions;

use RuntimeException;

final class ProjectLogbookException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($errorCode.': '.$message);
    }

    public static function invalid(string $message): self
    {
        return new self('logbook_request_invalid', $message);
    }

    public static function idempotencyConflict(): self
    {
        return new self(
            'logbook_idempotency_conflict',
            'The idempotency key was already used for different logbook content.',
            409,
        );
    }

    public static function referenceInvalid(string $message): self
    {
        return new self('logbook_reference_invalid', $message);
    }

    public static function referenceNotFound(): self
    {
        return new self(
            'logbook_reference_not_found',
            'The referenced resource does not exist in this project.',
            404,
        );
    }

    public static function secretDetected(): self
    {
        return new self(
            'logbook_secret_detected',
            'Logbook content appears to contain an unredacted token, credential, cookie, or secret.',
        );
    }
}
