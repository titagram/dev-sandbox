<?php

use App\Quality\Report\Finding;
use App\Quality\Report\QualityReport;
use App\Quality\Report\ReportWriter;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::deleteDirectory(base_path('var/quality'));
});

afterEach(function () {
    File::deleteDirectory(base_path('var/quality'));
});

it('writes quality reports as json and markdown under the quality reports directory', function () {
    $report = new QualityReport(
        tool: 'route-smoke',
        status: 'warning',
        generatedAt: new DateTimeImmutable('2026-06-23T17:00:00+00:00'),
        summary: [
            'total' => 2,
            'passed' => 1,
            'failed' => 0,
            'warnings' => 1,
            'skipped' => 0,
        ],
        findings: [
            new Finding(
                id: 'route-smoke.missing-config.dashboard.runs',
                severity: 'medium',
                type: 'missing_config',
                message: 'Route is not configured for smoke testing.',
                route: 'dashboard.runs.index',
                expected: 'route_registry entry',
                actual: 'missing',
                evidence: [
                    'uri' => '/runs',
                    'method' => 'GET',
                ],
            ),
        ],
    );

    $paths = app(ReportWriter::class)->write($report);

    expect($paths)->toBe([
        'json' => base_path('var/quality/reports/route-smoke.json'),
        'markdown' => base_path('var/quality/reports/route-smoke.md'),
    ]);

    expect(File::exists($paths['json']))->toBeTrue();
    expect(File::exists($paths['markdown']))->toBeTrue();

    $json = json_decode(File::get($paths['json']), true, 512, JSON_THROW_ON_ERROR);

    expect($json)->toMatchArray([
        'tool' => 'route-smoke',
        'status' => 'warning',
        'generated_at' => '2026-06-23T17:00:00+00:00',
        'summary' => [
            'total' => 2,
            'passed' => 1,
            'failed' => 0,
            'warnings' => 1,
            'skipped' => 0,
        ],
    ]);
    expect($json['findings'][0])->toMatchArray([
        'id' => 'route-smoke.missing-config.dashboard.runs',
        'severity' => 'medium',
        'type' => 'missing_config',
        'message' => 'Route is not configured for smoke testing.',
        'route' => 'dashboard.runs.index',
        'expected' => 'route_registry entry',
        'actual' => 'missing',
        'evidence' => [
            'uri' => '/runs',
            'method' => 'GET',
        ],
    ]);

    $markdown = File::get($paths['markdown']);

    expect($markdown)->toContain('# route-smoke Quality Report');
    expect($markdown)->toContain('Status: warning');
    expect($markdown)->toContain('route-smoke.missing-config.dashboard.runs');
    expect($markdown)->toContain('Route is not configured for smoke testing.');
});
