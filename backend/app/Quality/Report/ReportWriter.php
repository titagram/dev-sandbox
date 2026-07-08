<?php

namespace App\Quality\Report;

use Illuminate\Support\Facades\File;
use JsonException;

final class ReportWriter
{
    /**
     * @return array{json: string, markdown: string}
     *
     * @throws JsonException
     */
    public function write(QualityReport $report, ?string $directory = null): array
    {
        $directory ??= base_path('var/quality/reports');
        File::ensureDirectoryExists($directory);

        $baseName = $this->fileName($report->tool);
        $jsonPath = "{$directory}/{$baseName}.json";
        $markdownPath = "{$directory}/{$baseName}.md";

        File::put(
            $jsonPath,
            json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );
        File::put($markdownPath, $this->toMarkdown($report));

        return [
            'json' => $jsonPath,
            'markdown' => $markdownPath,
        ];
    }

    private function fileName(string $tool): string
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $tool) ?: 'quality-report';

        return trim($name, '-') ?: 'quality-report';
    }

    private function toMarkdown(QualityReport $report): string
    {
        $data = $report->toArray();
        $lines = [
            "# {$report->tool} Quality Report",
            '',
            "- Status: {$report->status}",
            "- Generated at: {$data['generated_at']}",
            "- Total: {$report->summary['total']}",
            "- Passed: {$report->summary['passed']}",
            "- Failed: {$report->summary['failed']}",
            "- Warnings: {$report->summary['warnings']}",
            "- Skipped: {$report->summary['skipped']}",
            '',
            '## Findings',
            '',
        ];

        if ($report->findings === []) {
            $lines[] = 'No findings.';

            return implode(PHP_EOL, $lines).PHP_EOL;
        }

        foreach ($report->findings as $finding) {
            $lines[] = "### {$finding->id}";
            $lines[] = '';
            $lines[] = "- Severity: {$finding->severity}";
            $lines[] = "- Type: {$finding->type}";
            $lines[] = "- Route: {$finding->route}";
            $lines[] = "- Expected: {$finding->expected}";
            $lines[] = "- Actual: {$finding->actual}";
            $lines[] = "- Message: {$finding->message}";
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}

