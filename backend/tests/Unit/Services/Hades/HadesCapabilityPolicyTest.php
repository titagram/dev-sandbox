<?php

use App\Services\Hades\HadesCapabilityPolicy;

it('exposes the exact supported Hades capability catalog', function () {
    expect(app(HadesCapabilityPolicy::class)->supportedNames())->toBe([
        'read_files',
        'read_source_slice',
        'project_inspection',
        'sync_git_tree',
        'populate_backend_ast',
        'populate_project_wiki',
        'verify_project_wiki',
        'write_project_logbook',
    ]);
});

it('never grants write_project_logbook through a legacy null allowlist', function () {
    expect(app(HadesCapabilityPolicy::class)->legacyNullGrantNames())
        ->not->toContain('write_project_logbook');
});

it('treats an explicitly empty allowlist as no capabilities', function () {
    $policy = app(HadesCapabilityPolicy::class);

    expect($policy->intersect($policy->supportedNames(), []))->toBe([]);
});
