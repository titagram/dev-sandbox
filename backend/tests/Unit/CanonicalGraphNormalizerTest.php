<?php

use App\Services\Graph\CanonicalGraphNormalizer;

it('normalizes legacy graph nodes and relationships while preserving graphify data', function () {
    $payload = [
        'graph_contract' => ['version' => 'hades.graph_artifact.v1', 'producer' => 'graphify'],
        'nodes' => [[
            'id' => 'function:App\\health',
            'labels' => ['Symbol', 'Function', 'PublicApi'],
            'properties' => ['language' => 'php', 'name' => 'existing-name'],
            'name' => 'health', 'kind' => 'function', 'path' => 'app.php',
            'line_start' => 10, 'line_end' => 12, 'confidence' => 0.98,
        ]],
        'relationships' => [[
            'id' => 'edge:1', 'type' => 'calls async',
            'source_id' => 'function:App\\health', 'target_id' => 'function:App\\status',
            'properties' => ['confidence' => 0.9],
        ]],
    ];
    $identity = ['repository_id' => 'repo-1', 'run_id' => 'run-1'];

    $normalized = (new CanonicalGraphNormalizer)->normalize($payload, $identity);

    expect($normalized)
        ->contract->toBe($payload['graph_contract'])
        ->identity->toBe($identity)
        ->nodes->toBe([[
            'id' => 'function:App\\health',
            'labels' => ['Symbol', 'Function', 'PublicApi'],
            'properties' => [
                'language' => 'php', 'name' => 'health', 'kind' => 'function',
                'path' => 'app.php', 'line_start' => 10, 'line_end' => 12, 'confidence' => 0.98,
            ],
        ]])
        ->relationships->toBe([[
            'id' => 'edge:1', 'type' => 'CALLS_ASYNC',
            'source_id' => 'function:App\\health', 'target_id' => 'function:App\\status',
            'properties' => ['confidence' => 0.9],
        ]])
        ->stats->toBe(['nodes' => 1, 'relationships' => 1]);
});

it('normalizes Hades symbols and edges into the canonical graph shape', function () {
    $normalized = (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => ['version' => 'hades.graph_artifact.v1'],
        'symbols' => [[
            'symbol_id' => 'method:User::save', 'name' => 'save',
            'kind' => 'method', 'path' => 'app/User.php',
        ]],
        'edges' => [[
            'kind' => 'calls.method', 'source' => 'method:User::save', 'target' => 'function:persist',
        ]],
    ], ['project_id' => 'project-1']);

    expect($normalized['nodes'])->toBe([[
        'id' => 'method:User::save', 'labels' => ['Symbol', 'Method'],
        'properties' => ['name' => 'save', 'kind' => 'method', 'path' => 'app/User.php'],
    ]]);
    expect($normalized['relationships'])->toBe([[
        'type' => 'CALLS_METHOD', 'source_id' => 'method:User::save',
        'target_id' => 'function:persist', 'properties' => [],
    ]]);
});

it('rejects nodes with blank identifiers', function () {
    (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => ['version' => 'hades.graph_artifact.v1'],
        'nodes' => [['id' => '   ', 'kind' => 'class']],
    ], []);
})->throws(InvalidArgumentException::class, 'Canonical graph node id is missing.');

it('rejects edges with blank endpoints', function () {
    (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => ['version' => 'hades.graph_artifact.v1'],
        'edges' => [['kind' => 'calls', 'source' => 'node:a', 'target' => '']],
    ], []);
})->throws(InvalidArgumentException::class, 'Canonical graph edge endpoints are missing.');

it('rejects missing and unsupported graph contracts', function (array $payload) {
    (new CanonicalGraphNormalizer)->normalize($payload, []);
})->with([
    'missing contract' => [[]],
    'unsupported contract version' => [['graph_contract' => ['version' => 'hades.graph_artifact.v2']]],
])->throws(InvalidArgumentException::class, 'Canonical graph contract is missing or unsupported.');
