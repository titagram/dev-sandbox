<?php

use App\Services\Graph\DashboardGraphPublicKind;

uses(Tests\TestCase::class);

it('maps only the exact public graph kind vocabulary', function (): void {
    $mapper = new DashboardGraphPublicKind;
    $expected = [
        'method',
        'class',
        'method_reference',
        'external_class',
        'table',
        'route',
        'trait',
        'external_symbol',
        'interface',
        'file',
    ];

    foreach ($expected as $kind) {
        expect($mapper->map($kind))->toBe($kind);
    }
});

it('maps producer aliases and arbitrary values to unknown', function (): void {
    $mapper = new DashboardGraphPublicKind;

    foreach (['service', 'module', 'function', 'model', 'enum', 'http_endpoint', 'endpoint', 'hades-public-v1-node', '/srv/private/Foo.php', null, ['kind']] as $kind) {
        expect($mapper->map($kind))->toBe('unknown');
    }
});
