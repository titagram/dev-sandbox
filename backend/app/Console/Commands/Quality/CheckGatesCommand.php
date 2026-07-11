<?php

namespace App\Console\Commands\Quality;

use App\Quality\Gate\QualityGateEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

final class CheckGatesCommand extends Command
{
    protected $signature = 'quality:check-gates
        {--gate=pull_request : Quality gate to evaluate}
        {--format=json : Primary output format, json or md}
        {--output= : Optional primary output path}';

    protected $description = 'Evaluate quality reports against configured quality gates';

    /**
     * @throws JsonException
     */
    public function handle(QualityGateEvaluator $evaluator): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['json', 'md'], true)) {
            $this->error('Invalid --format value. Use json or md.');

            return self::FAILURE;
        }

        $gate = (string) $this->option('gate');

        try {
            $report = $evaluator->evaluate($gate);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $directory = base_path('var/quality/reports');
        $baseName = 'quality-gate-'.$gate;
        $jsonPath = $format === 'json' && $this->option('output')
            ? (string) $this->option('output')
            : "{$directory}/{$baseName}.json";
        $markdownPath = $format === 'md' && $this->option('output')
            ? (string) $this->option('output')
            : "{$directory}/{$baseName}.md";

        File::ensureDirectoryExists(dirname($jsonPath));
        File::ensureDirectoryExists(dirname($markdownPath));
        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        File::put($markdownPath, $this->markdown($report));

        $this->info("Wrote quality gate JSON to {$jsonPath}.");
        $this->info("Wrote quality gate Markdown to {$markdownPath}.");

        return $report['status'] === 'fail' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function markdown(array $report): string
    {
        $lines = [
            "# Quality Gate: {$report['gate']}",
            '',
            "- Status: {$report['status']}",
            "- Generated at: {$report['generated_at']}",
            "- Failed: {$report['summary']['failed']}",
            "- Warnings: {$report['summary']['warnings']}",
            '',
            '| Decision | Severity | Type | Source | Finding |',
            '| --- | --- | --- | --- | --- |',
        ];

        foreach ($report['findings'] as $finding) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $finding['gate_decision'],
                $finding['severity'],
                $finding['type'],
                $finding['source_tool'],
                $finding['id'],
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
