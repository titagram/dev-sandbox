<?php

namespace App\Quality\Report;

final readonly class Finding
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $id,
        public string $severity,
        public string $type,
        public string $message,
        public string $route = '',
        public string $expected = '',
        public string $actual = '',
        public array $evidence = [],
    ) {}

    /**
     * @return array{
     *     id: string,
     *     severity: string,
     *     type: string,
     *     message: string,
     *     route: string,
     *     expected: string,
     *     actual: string,
     *     evidence: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity,
            'type' => $this->type,
            'message' => $this->message,
            'route' => $this->route,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'evidence' => $this->evidence,
        ];
    }
}
