<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::deleteDirectory(base_path('var/quality'));
    File::ensureDirectoryExists(base_path('var/quality/reports'));
});

afterEach(function () {
    File::deleteDirectory(base_path('var/quality'));
});

it('fails pull request gate on blocking findings and keeps setup gaps as warnings', function () {
    writeQualityReport('route-inventory', [
        finding('route-inventory.missing-config.login', 'medium', 'missing_config'),
        finding('route-inventory.missing-param.projects-show', 'medium', 'missing_parameter_provider'),
    ]);
    writeQualityReport('route-smoke', [
        finding('route-smoke.5xx.kanban', 'high', 'route_5xx'),
        finding('route-smoke.status.runs', 'medium', 'unexpected_status'),
        finding('route-smoke.status.wiki', 'high', 'unexpected_status'),
    ]);
    writeQualityReport('composer-audit', [
        finding('composer-audit.advisory.guzzle', 'high', 'composer_audit_advisory'),
    ]);
    writeQualityReport('secret-scan', [
        finding('secret-scan.detected.env', 'critical', 'secret_detected'),
    ]);

    $exitCode = Artisan::call('quality:check-gates', [
        '--gate' => 'pull_request',
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(1);

    $jsonPath = base_path('var/quality/reports/quality-gate-pull_request.json');
    $markdownPath = base_path('var/quality/reports/quality-gate-pull_request.md');

    expect(File::exists($jsonPath))->toBeTrue();
    expect(File::exists($markdownPath))->toBeTrue();

    $report = json_decode(File::get($jsonPath), true, 512, JSON_THROW_ON_ERROR);

    expect($report['tool'])->toBe('quality-gate');
    expect($report['gate'])->toBe('pull_request');
    expect($report['status'])->toBe('fail');
    expect($report['summary']['failed'])->toBe(4);
    expect($report['summary']['warnings'])->toBe(3);

    $decisions = collect($report['findings'])->pluck('gate_decision', 'id');

    expect($decisions['route-smoke.5xx.kanban'])->toBe('blocking');
    expect($decisions['route-smoke.status.wiki'])->toBe('blocking');
    expect($decisions['composer-audit.advisory.guzzle'])->toBe('blocking');
    expect($decisions['secret-scan.detected.env'])->toBe('blocking');
    expect($decisions['route-smoke.status.runs'])->toBe('warning');
    expect($decisions['route-inventory.missing-config.login'])->toBe('warning');
    expect($decisions['route-inventory.missing-param.projects-show'])->toBe('warning');
    expect(File::get($markdownPath))->toContain('# Quality Gate: pull_request');
});

it('passes pull request gate with non blocking warnings only', function () {
    writeQualityReport('route-inventory', [
        finding('route-inventory.missing-config.login', 'medium', 'missing_config'),
    ]);
    writeQualityReport('route-smoke', [
        finding('route-smoke.missing-param.projects-show', 'medium', 'missing_parameter_provider'),
        finding('route-smoke.status.runs', 'medium', 'unexpected_status'),
    ]);

    $exitCode = Artisan::call('quality:check-gates', [
        '--gate' => 'pull_request',
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0);

    $report = json_decode(
        File::get(base_path('var/quality/reports/quality-gate-pull_request.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($report['status'])->toBe('pass');
    expect($report['summary']['failed'])->toBe(0);
    expect($report['summary']['warnings'])->toBe(3);
});

/**
 * @param  list<array<string, mixed>>  $findings
 */
function writeQualityReport(string $tool, array $findings): void
{
    File::put(
        base_path("var/quality/reports/{$tool}.json"),
        json_encode([
            'tool' => $tool,
            'status' => $findings === [] ? 'pass' : 'warning',
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total' => count($findings),
                'passed' => 0,
                'failed' => 0,
                'warnings' => count($findings),
                'skipped' => 0,
            ],
            'findings' => $findings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );
}

/**
 * @return array<string, mixed>
 */
function finding(string $id, string $severity, string $type): array
{
    return [
        'id' => $id,
        'severity' => $severity,
        'type' => $type,
        'message' => "{$type} test finding",
        'route' => '',
        'expected' => '',
        'actual' => '',
        'evidence' => [],
    ];
}
