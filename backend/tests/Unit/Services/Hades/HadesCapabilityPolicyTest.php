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
    ]);
});

it('treats an explicitly empty allowlist as no capabilities', function () {
    $policy = app(HadesCapabilityPolicy::class);

    expect($policy->intersect($policy->supportedNames(), []))->toBe([]);
});
