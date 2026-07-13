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
]);
