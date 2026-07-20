<?php

namespace App\Services\Graph\V2;

final class GraphV2ImportException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
