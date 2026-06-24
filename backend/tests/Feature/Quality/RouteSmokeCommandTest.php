<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::deleteDirectory(base_path('var/quality'));
});

afterEach(function () {
    File::deleteDirectory(base_path('var/quality'));
});

it('runs guest smoke only for configured safe read routes and skips unsafe or underconfigured routes', function () {
    $exitCode = Artisan::call('quality:route-smoke', [
        '--actor' => 'guest',
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0);

    $jsonPath = base_path('var/quality/reports/route-smoke.json');
    $markdownPath = base_path('var/quality/reports/route-smoke.md');

    expect(File::exists($jsonPath))->toBeTrue();
    expect(File::exists($markdownPath))->toBeTrue();

    $report = json_decode(File::get($jsonPath), true, 512, JSON_THROW_ON_ERROR);

    expect($report['tool'])->toBe('route-smoke');
    expect($report['summary']['passed'])->toBeGreaterThan(0);
    expect($report['summary']['failed'])->toBe(0);
    expect($report['summary']['skipped'])->toBeGreaterThan(0);

    $executed = collect($report['results'])->where('executed', true);

    expect($executed)->not->toBeEmpty();
    expect($executed->pluck('classification')->unique()->values()->all())->toBe(['SAFE_READ']);
    expect($executed->pluck('method')->unique()->values()->all())->toBe(['GET']);
    expect($executed->pluck('uri'))->toContain('kanban');
    expect($executed->firstWhere('uri', 'kanban')['actual_status'])->toBe(302);

    $review = collect($report['results'])->firstWhere('uri', 'runs/{run}/review');

    expect($review)->not->toBeNull();
    expect($review['executed'])->toBeFalse();
    expect($review['classification'])->toBe('MUTATING');
    expect($review['skip_reason'])->toBe('unsafe_classification');

    $project = collect($report['results'])->firstWhere('uri', 'projects/{project}');

    expect($project)->not->toBeNull();
    expect($project['executed'])->toBeFalse();
    expect($project['skip_reason'])->toBe('missing_parameter_provider');

    expect(File::get($markdownPath))->toContain('# route-smoke Quality Report');
});

