<?php

use App\Services\Graph\CanonicalGraphNormalizer;

function unitCanonicalGraphContract(array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 'hades.graph_artifact.v1',
        'extractor' => [
            'name' => 'hades-native-php',
            'version' => '1',
            'mode' => 'native',
            'quality' => 'full',
            'fallback_reason' => null,
        ],
        'coverage' => [
            'languages' => ['php'],
            'files_total' => 1,
            'files_analyzed' => 1,
            'files_failed' => 0,
        ],
        'source' => ['branch' => 'main', 'head_commit' => str_repeat('a', 40)],
    ], $overrides);
}

it('accepts the bounded additive coverage inventory', function (): void {
    $coverage = [
        'languages' => ['php', 'typescript'],
        'files_total' => 12,
        'files_analyzed' => 9,
        'files_failed' => 3,
        'files_budget_omitted' => 2,
        'routes_promoted' => 4,
        'routes_omitted' => 1,
        'tests_promoted' => 3,
        'tests_omitted' => 1,
        'nodes_capacity_omitted' => 5,
    ];

    $normalized = (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => unitCanonicalGraphContract(['coverage' => $coverage]),
        'nodes' => [],
        'relationships' => [],
    ], ['project_id' => 'project-1']);

    expect($normalized['contract']['coverage'])->toBe($coverage);
});

it('normalizes legacy graph nodes and relationships while preserving graphify data', function () {
    $payload = [
        'graph_contract' => unitCanonicalGraphContract([
            'extractor' => ['name' => 'graphify', 'mode' => 'graphify'],
        ]),
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
        'graph_contract' => unitCanonicalGraphContract(),
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
        'graph_contract' => unitCanonicalGraphContract(),
        'nodes' => [['id' => '   ', 'kind' => 'class']],
    ], []);
})->throws(InvalidArgumentException::class, 'Canonical graph node id is missing.');

it('rejects edges with blank endpoints', function () {
    (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => unitCanonicalGraphContract(),
        'edges' => [['kind' => 'calls', 'source' => 'node:a', 'target' => '']],
    ], []);
})->throws(InvalidArgumentException::class, 'Canonical graph edge endpoints are missing.');

it('rejects explicit node property bags that are not string-keyed maps', function (mixed $properties) {
    (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => unitCanonicalGraphContract(),
        'nodes' => [['id' => 'class:InvalidProperties', 'properties' => $properties]],
    ], []);
})->with([
    'null' => [null],
    'non-empty list' => [['first', 'second']],
    'mixed numeric and string keys' => [[0 => 'first', 'name' => 'ValidName']],
])->throws(InvalidArgumentException::class, 'Canonical graph node properties must be a map.');

it('rejects explicit edge property bags that are not string-keyed maps', function (mixed $properties) {
    (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => unitCanonicalGraphContract(),
        'nodes' => [
            ['id' => 'class:Source'],
            ['id' => 'class:Target'],
        ],
        'relationships' => [[
            'type' => 'CALLS',
            'source_id' => 'class:Source',
            'target_id' => 'class:Target',
            'properties' => $properties,
        ]],
    ], []);
})->with([
    'null' => [null],
    'non-empty list' => [['first', 'second']],
    'mixed numeric and string keys' => [[0 => 'first', 'confidence' => 0.9]],
])->throws(InvalidArgumentException::class, 'Canonical graph edge properties must be a map.');

it('accepts omitted and empty property bags while preserving maps and nested list values', function () {
    $normalized = (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => unitCanonicalGraphContract(),
        'nodes' => [
            ['id' => 'class:Omitted'],
            ['id' => 'class:Empty', 'properties' => []],
            ['id' => 'class:Mapped', 'properties' => [
                'name' => 'Mapped',
                'tags' => ['domain', 'public'],
            ]],
        ],
        'relationships' => [
            [
                'type' => 'CALLS',
                'source_id' => 'class:Omitted',
                'target_id' => 'class:Empty',
            ],
            [
                'type' => 'CALLS',
                'source_id' => 'class:Empty',
                'target_id' => 'class:Mapped',
                'properties' => [],
            ],
            [
                'type' => 'CALLS',
                'source_id' => 'class:Mapped',
                'target_id' => 'class:Omitted',
                'properties' => [
                    'confidence' => 0.9,
                    'evidence_lines' => [10, 14],
                ],
            ],
        ],
    ], []);

    expect($normalized['nodes'][0]['properties'])->toBe([])
        ->and($normalized['nodes'][1]['properties'])->toBe([])
        ->and($normalized['nodes'][2]['properties'])->toBe([
            'name' => 'Mapped',
            'tags' => ['domain', 'public'],
        ])
        ->and($normalized['relationships'][0]['properties'])->toBe([])
        ->and($normalized['relationships'][1]['properties'])->toBe([])
        ->and($normalized['relationships'][2]['properties'])->toBe([
            'confidence' => 0.9,
            'evidence_lines' => [10, 14],
        ]);
});

it('rejects missing and unsupported graph contracts', function (array $payload) {
    (new CanonicalGraphNormalizer)->normalize($payload, []);
})->with([
    'missing contract' => [[]],
    'unsupported contract version' => [['graph_contract' => ['version' => 'hades.graph_artifact.v2']]],
])->throws(InvalidArgumentException::class, 'Canonical graph contract is missing or unsupported.');

it('rejects malformed explicit canonical graph contracts before graph normalization', function (string $mutation, string $field) {
    $contract = unitCanonicalGraphContract();
    if ($mutation === 'missing_extractor') {
        unset($contract['extractor']);
    } elseif ($mutation === 'missing_fallback_reason') {
        unset($contract['extractor']['fallback_reason']);
    } elseif ($mutation === 'invalid_mode') {
        $contract['extractor']['mode'] = 'full';
    } elseif ($mutation === 'invalid_quality') {
        $contract['extractor']['quality'] = 'complete';
    } elseif ($mutation === 'invalid_name_type') {
        $contract['extractor']['name'] = 123;
    } elseif ($mutation === 'invalid_count_type') {
        $contract['coverage']['files_total'] = '1';
    } elseif ($mutation === 'invalid_language_type') {
        $contract['coverage']['languages'] = ['php', 123];
    } elseif ($mutation === 'missing_head_commit') {
        unset($contract['source']['head_commit']);
    } elseif ($mutation === 'invalid_branch_type') {
        $contract['source']['branch'] = ['main'];
    } elseif ($mutation === 'unexpected_extractor_key') {
        $contract['extractor']['producer'] = 'shadow';
    } elseif ($mutation === 'unexpected_coverage_key') {
        $contract['coverage']['raw_paths'] = ['/private/source.php'];
    } elseif ($mutation === 'invalid_optional_count') {
        $contract['coverage']['routes_promoted'] = -1;
    }

    expect(fn () => (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => $contract,
        'nodes' => [['id' => 'class:ValidIdentity']],
    ], []))->toThrow(InvalidArgumentException::class, "Canonical graph contract is malformed at {$field}.");
})->with([
    'missing extractor' => ['missing_extractor', 'graph_contract'],
    'missing fallback reason' => ['missing_fallback_reason', 'extractor'],
    'invalid extractor mode' => ['invalid_mode', 'extractor.mode'],
    'invalid extractor quality' => ['invalid_quality', 'extractor.quality'],
    'invalid extractor name type' => ['invalid_name_type', 'extractor.name'],
    'invalid coverage count type' => ['invalid_count_type', 'coverage.files_total'],
    'invalid coverage language type' => ['invalid_language_type', 'coverage.languages'],
    'missing source head commit' => ['missing_head_commit', 'source'],
    'invalid source branch type' => ['invalid_branch_type', 'source.branch'],
    'unexpected extractor key' => ['unexpected_extractor_key', 'extractor'],
    'unexpected coverage key' => ['unexpected_coverage_key', 'coverage'],
    'invalid optional coverage count' => ['invalid_optional_count', 'coverage.routes_promoted'],
]);

it('accepts bounded metadata emitted by current native and supported legacy producers', function (array $contract) {
    $normalized = (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => $contract,
        'nodes' => [['id' => 'class:Compatible']],
    ], []);

    expect($normalized['contract'])->toBe($contract);
})->with([
    'native partial' => [[
        'version' => 'hades.graph_artifact.v1',
        'extractor' => ['name' => 'hades-native-typescript', 'version' => '1', 'mode' => 'native', 'quality' => 'partial', 'fallback_reason' => 'canonicalization_omissions'],
        'coverage' => ['languages' => ['typescript'], 'files_total' => 12, 'files_analyzed' => 10, 'files_failed' => 2],
        'source' => ['branch' => 'feature/canonical-graph', 'head_commit' => str_repeat('a', 40)],
    ]],
    'graphify failure' => [[
        'version' => 'hades.graph_artifact.v1',
        'extractor' => ['name' => 'graphify', 'version' => '1.0', 'mode' => 'fallback', 'quality' => 'inventory_only', 'fallback_reason' => 'graphify_failed:RuntimeError'],
        'coverage' => ['languages' => ['python'], 'files_total' => 1, 'files_analyzed' => 1, 'files_failed' => 0],
        'source' => ['branch' => null, 'head_commit' => null],
    ]],
    'legacy adapter' => [[
        'version' => 'hades.graph_artifact.v1',
        'extractor' => ['name' => 'hades-legacy-php', 'version' => '1', 'mode' => 'legacy_adapter', 'quality' => 'partial', 'fallback_reason' => 'missing_contract_metadata'],
        'coverage' => ['languages' => ['php'], 'files_total' => 1, 'files_analyzed' => 1, 'files_failed' => 0],
        'source' => ['branch' => 'main', 'head_commit' => 'abc123'],
    ]],
]);

it('rejects unbounded, control-bearing, or grammatically invalid canonical metadata', function (Closure $mutate, string $field) {
    $contract = unitCanonicalGraphContract();
    $mutate($contract);

    expect(fn () => (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => $contract,
        'nodes' => [['id' => 'class:ValidIdentity']],
    ], []))->toThrow(InvalidArgumentException::class, "Canonical graph contract is malformed at {$field}.");
})->with([
    'oversized extractor name' => [fn (array &$c) => $c['extractor']['name'] = str_repeat('a', 65), 'extractor.name'],
    'control in extractor version' => [fn (array &$c) => $c['extractor']['version'] = "1\nsecret", 'extractor.version'],
    'too many languages' => [fn (array &$c) => $c['coverage']['languages'] = array_fill(0, 17, 'php'), 'coverage.languages'],
    'oversized language' => [fn (array &$c) => $c['coverage']['languages'] = [str_repeat('p', 33)], 'coverage.languages'],
    'invalid language grammar' => [fn (array &$c) => $c['coverage']['languages'] = ['../../private'], 'coverage.languages'],
    'oversized branch' => [fn (array &$c) => $c['source']['branch'] = str_repeat('b', 256), 'source.branch'],
    'control in branch' => [fn (array &$c) => $c['source']['branch'] = "main\n/private", 'source.branch'],
    'dot branch segment' => [fn (array &$c) => $c['source']['branch'] = 'feature/./graph', 'source.branch'],
    'parent branch segment' => [fn (array &$c) => $c['source']['branch'] = 'feature/../graph', 'source.branch'],
    'consecutive dots' => [fn (array &$c) => $c['source']['branch'] = 'feature..graph', 'source.branch'],
    'consecutive slashes' => [fn (array &$c) => $c['source']['branch'] = 'feature//graph', 'source.branch'],
    'trailing slash' => [fn (array &$c) => $c['source']['branch'] = 'feature/graph/', 'source.branch'],
    'trailing dot' => [fn (array &$c) => $c['source']['branch'] = 'feature/graph.', 'source.branch'],
    'lock suffix component' => [fn (array &$c) => $c['source']['branch'] = 'feature/graph.lock', 'source.branch'],
    'reflog selector' => [fn (array &$c) => $c['source']['branch'] = 'feature@{1', 'source.branch'],
    'backslash' => [fn (array &$c) => $c['source']['branch'] = 'feature\\graph', 'source.branch'],
    'invalid head commit' => [fn (array &$c) => $c['source']['head_commit'] = 'not-a-commit', 'source.head_commit'],
    'oversized head commit' => [fn (array &$c) => $c['source']['head_commit'] = str_repeat('a', 81), 'source.head_commit'],
    'free-form fallback text' => [fn (array &$c) => $c['extractor']['fallback_reason'] = 'failed at /Users/private/file.php', 'extractor.fallback_reason'],
    'oversized graphify exception code' => [fn (array &$c) => $c['extractor']['fallback_reason'] = 'graphify_failed:'.str_repeat('E', 65), 'extractor.fallback_reason'],
]);

it('rejects impossible canonical coverage and quality combinations', function (Closure $mutate, string $field) {
    $contract = unitCanonicalGraphContract();
    $mutate($contract);

    expect(fn () => (new CanonicalGraphNormalizer)->normalize([
        'graph_contract' => $contract,
        'nodes' => [['id' => 'class:ValidIdentity']],
    ], []))->toThrow(InvalidArgumentException::class, "Canonical graph contract is malformed at {$field}.");
})->with([
    'analyzed exceeds total' => [fn (array &$c) => $c['coverage']['files_analyzed'] = 2, 'coverage'],
    'failed exceeds total' => [fn (array &$c) => $c['coverage']['files_failed'] = 2, 'coverage'],
    'counts do not partition total' => [function (array &$c) {
        $c['coverage']['files_total'] = 3;
        $c['coverage']['files_analyzed'] = 1;
    }, 'coverage'],
    'budget omissions exceed failed files' => [function (array &$c) {
        $c['coverage']['files_failed'] = 1;
        $c['coverage']['files_analyzed'] = 0;
        $c['coverage']['files_budget_omitted'] = 2;
    }, 'coverage.files_budget_omitted'],
    'full quality has fallback reason' => [fn (array &$c) => $c['extractor']['fallback_reason'] = 'bounded_or_omitted_input', 'extractor'],
    'partial quality lacks fallback reason' => [function (array &$c) {
        $c['extractor']['quality'] = 'partial';
        $c['extractor']['fallback_reason'] = null;
    }, 'extractor'],
]);
