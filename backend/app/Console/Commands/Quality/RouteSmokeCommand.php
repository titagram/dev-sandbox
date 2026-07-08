<?php

namespace App\Console\Commands\Quality;

use App\Quality\Route\RouteSmokeRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

final class RouteSmokeCommand extends Command
{
    protected $signature = 'quality:route-smoke
        {--actor=guest : Actor to smoke as}
        {--format=json : Primary output format, json or md}
        {--output= : Optional primary output path}
        {--allow-mutating=false : Allow mutating routes}
        {--allow-destructive=false : Allow destructive or external side-effect routes}';

    protected $description = 'Run safe Laravel route smoke checks for configured routes';

    /**
     * @throws JsonException
     */
    public function handle(RouteSmokeRunner $runner): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['json', 'md'], true)) {
            $this->error('Invalid --format value. Use json or md.');

            return self::FAILURE;
        }

        $report = $runner->run(
            actor: (string) $this->option('actor'),
            allowMutating: $this->option('allow-mutating') === 'true',
            allowDestructive: $this->option('allow-destructive') === 'true',
        );

        $directory = base_path('var/quality/reports');
        $jsonPath = $format === 'json' && $this->option('output')
            ? (string) $this->option('output')
            : "{$directory}/route-smoke.json";
        $markdownPath = $format === 'md' && $this->option('output')
            ? (string) $this->option('output')
            : "{$directory}/route-smoke.md";

        File::ensureDirectoryExists(dirname($jsonPath));
        File::ensureDirectoryExists(dirname($markdownPath));
        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        File::put($markdownPath, $this->markdown($report));

        $this->info("Wrote route smoke JSON to {$jsonPath}.");
        $this->info("Wrote route smoke Markdown to {$markdownPath}.");

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function markdown(array $report): string
    {
        $lines = [
            '# route-smoke Quality Report',
            '',
            "- Status: {$report['status']}",
            "- Actor: {$report['actor']}",
            "- Generated at: {$report['generated_at']}",
            "- Passed: {$report['summary']['passed']}",
            "- Failed: {$report['summary']['failed']}",
            "- Warnings: {$report['summary']['warnings']}",
            "- Skipped: {$report['summary']['skipped']}",
            '',
            '| Method | URI | Classification | Result | Status |',
            '| --- | --- | --- | --- | --- |',
        ];

        foreach ($report['results'] as $result) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $result['method'],
                $result['uri'],
                $result['classification'],
                $result['executed'] ? ($result['passed'] ? 'passed' : 'failed') : 'skipped',
                $result['actual_status'] ?? $result['skip_reason'],
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}

