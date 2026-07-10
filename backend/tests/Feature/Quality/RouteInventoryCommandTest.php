<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::deleteDirectory(base_path('var/quality'));
});

afterEach(function () {
    File::deleteDirectory(base_path('var/quality'));
});

it('writes route inventory json with route metadata and registry classification', function () {
    $exitCode = Artisan::call('quality:route-inventory', [
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0);

    $path = base_path('var/quality/reports/route-inventory.json');
    expect(File::exists($path))->toBeTrue();

    $inventory = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

    expect($inventory)->toHaveKeys([
        'tool',
        'status',
        'generated_at',
        'summary',
        'routes',
        'findings',
    ]);
    expect($inventory['tool'])->toBe('route-inventory');
    expect($inventory['summary']['total'])->toBeGreaterThan(0);

    $kanban = collect($inventory['routes'])->firstWhere('uri', 'kanban');

    expect($kanban)->not->toBeNull();
    expect($kanban)->toHaveKeys([
        'name',
        'uri',
        'methods',
        'action',
        'controller',
        'parameters',
        'classification',
        'configured',
        'warnings',
    ]);
    expect($kanban['methods'])->toContain('GET');
    expect($kanban['action'])->toContain('KanbanController');
    expect($kanban['controller'])->toContain('KanbanController');
    expect($kanban['parameters'])->toBe([]);
    expect($kanban['classification'])->toBe('SAFE_READ');
    expect($kanban['configured'])->toBeTrue();
    expect($kanban['warnings'])->toBe([]);

    $login = collect($inventory['routes'])->firstWhere('uri', 'login');

    expect($login)->not->toBeNull();
    expect($login['classification'])->toBe('UNKNOWN');
    expect($login['configured'])->toBeFalse();
    expect($login['warnings'])->toContain('missing_config');
});
