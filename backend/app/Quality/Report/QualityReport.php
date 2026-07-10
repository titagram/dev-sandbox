<?php

namespace App\Quality\Report;

use DateTimeInterface;

final readonly class QualityReport
{
    /**
     * @param  array{total: int, passed: int, failed: int, warnings: int, skipped: int}  $summary
     * @param  list<Finding>  $findings
     */
    public function __construct(
        public string $tool,
        public string $status,
        public DateTimeInterface $generatedAt,
        public array $summary,
        public array $findings = [],
    ) {}

    /**
     * @return array{
     *     tool: string,
     *     status: string,
     *     generated_at: string,
     *     summary: array{total: int, passed: int, failed: int, warnings: int, skipped: int},
     *     findings: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'status' => $this->status,
            'generated_at' => $this->generatedAt->format(DateTimeInterface::ATOM),
            'summary' => $this->summary,
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
