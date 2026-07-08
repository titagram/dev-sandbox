<?php

namespace App\Quality\Gate;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final class QualityGateEvaluator
{
    /**
     * @var array<string, int>
     */
    private const SEVERITY_RANKS = [
        'info' => 0,
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    public function __construct(
        private readonly ?string $configPath = null,
        private readonly ?string $reportsDirectory = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(string $gate): array
    {
        $gateConfig = $this->gateConfig($gate);
        $reports = $this->loadReports();
        $findings = [];

        foreach ($reports as $report) {
            $tool = (string) ($report['tool'] ?? 'unknown');

            foreach (($report['findings'] ?? []) as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $normalized = $this->normalizeFinding($finding, $tool);
                $findings[] = [
                    ...$normalized,
                    'source_tool' => $tool,
                    'gate_decision' => $this->decision($normalized, $gateConfig),
                ];
            }
        }

        foreach ($this->missingRequiredReports($gateConfig, $reports) as $missingReport) {
            $findings[] = $this->missingReportFinding($missingReport);
        }

        usort($findings, static function (array $left, array $right): int {
            $decisionRank = ['blocking' => 0, 'warning' => 1, 'ignored' => 2];

            return [
                $decisionRank[$left['gate_decision']] ?? 3,
                $left['source_tool'],
                $left['id'],
            ] <=> [
                $decisionRank[$right['gate_decision']] ?? 3,
                $right['source_tool'],
                $right['id'],
            ];
        });

        $failed = count(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['gate_decision'] === 'blocking',
        ));
        $warnings = count(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['gate_decision'] === 'warning',
        ));

        return [
            'tool' => 'quality-gate',
            'gate' => $gate,
            'status' => $failed > 0 ? 'fail' : 'pass',
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total' => count($findings),
                'passed' => count($findings) - $failed - $warnings,
                'failed' => $failed,
                'warnings' => $warnings,
                'skipped' => 0,
            ],
            'required_reports' => array_values($gateConfig['required_reports'] ?? []),
            'source_reports' => array_map(
                static fn (array $report): array => [
                    'tool' => (string) ($report['tool'] ?? 'unknown'),
                    'status' => (string) ($report['status'] ?? 'unknown'),
                    'generated_at' => (string) ($report['generated_at'] ?? ''),
                ],
                $reports,
            ),
            'findings' => $findings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gateConfig(string $gate): array
    {
        $path = $this->configPath ?? base_path('config/quality/quality_gates.yaml');
        $data = file_exists($path) ? Yaml::parseFile($path) : [];
        $gates = is_array($data) ? ($data['gates'] ?? []) : [];
        $gateConfig = is_array($gates) ? ($gates[$gate] ?? null) : null;

        if (! is_array($gateConfig)) {
            throw new InvalidArgumentException("Unknown quality gate [{$gate}].");
        }

        return $gateConfig;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadReports(): array
    {
        $directory = $this->reportsDirectory ?? base_path('var/quality/reports');
        $reports = [];

        foreach (File::glob("{$directory}/*.json") ?: [] as $path) {
            $data = json_decode((string) File::get($path), true);

            if (! is_array($data)) {
                continue;
            }

            $tool = (string) ($data['tool'] ?? '');

            if ($tool === 'quality-gate' || str_starts_with(basename($path), 'quality-gate-')) {
                continue;
            }

            $reports[] = $data;
        }

        usort(
            $reports,
            static fn (array $left, array $right): int => ((string) ($left['tool'] ?? '')) <=> ((string) ($right['tool'] ?? '')),
        );

        return $reports;
    }

    /**
     * @param array<string, mixed> $gateConfig
     * @param list<array<string, mixed>> $reports
     * @return list<string>
     */
    private function missingRequiredReports(array $gateConfig, array $reports): array
    {
        $present = [];

        foreach ($reports as $report) {
            $present[(string) ($report['tool'] ?? '')] = true;
        }

        return array_values(array_filter(
            array_map('strval', $gateConfig['required_reports'] ?? []),
            static fn (string $tool): bool => ! isset($present[$tool]),
        ));
    }

    /**
     * @param array<string, mixed> $finding
     * @param array<string, mixed> $gateConfig
     */
    private function decision(array $finding, array $gateConfig): string
    {
        foreach (($gateConfig['fail_on'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if (($rule['type'] ?? null) !== $finding['type']) {
                continue;
            }

            if ($this->severityMeets((string) $finding['severity'], (string) ($rule['severity_at_least'] ?? 'low'))) {
                return 'blocking';
            }
        }

        return 'warning';
    }

    private function severityMeets(string $actual, string $threshold): bool
    {
        return $this->severityRank($actual) >= $this->severityRank($threshold);
    }

    private function severityRank(string $severity): int
    {
        return self::SEVERITY_RANKS[strtolower($severity)] ?? self::SEVERITY_RANKS['info'];
    }

    /**
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function normalizeFinding(array $finding, string $tool): array
    {
        $id = (string) ($finding['id'] ?? "{$tool}.finding");

        return [
            'id' => $id,
            'severity' => strtolower((string) ($finding['severity'] ?? 'info')),
            'type' => (string) ($finding['type'] ?? 'unknown'),
            'message' => (string) ($finding['message'] ?? ''),
            'route' => (string) ($finding['route'] ?? ''),
            'expected' => (string) ($finding['expected'] ?? ''),
            'actual' => (string) ($finding['actual'] ?? ''),
            'evidence' => is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingReportFinding(string $tool): array
    {
        return [
            'id' => "quality-gate.missing-report.{$tool}",
            'severity' => 'medium',
            'type' => 'missing_setup',
            'message' => "Required report [{$tool}] was not found.",
            'route' => '',
            'expected' => 'required report present',
            'actual' => 'missing report',
            'evidence' => ['tool' => $tool],
            'source_tool' => 'quality-gate',
            'gate_decision' => 'warning',
        ];
    }
}
