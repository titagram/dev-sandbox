<?php

use App\Services\GenesisGraphImportService;
use App\Services\Neo4j\FakeNeo4jClient;

it('builds a DevBoardSnapshot command payload', function () {
    $command = GenesisGraphImportService::devBoardSnapshotCommand('snap_123', 'repo_123', 'run_123');

    expect($command['cypher'])->toContain('DevBoardSnapshot');
    expect($command['params'])->toMatchArray([
        'snapshot_id' => 'snap_123',
        'repository_id' => 'repo_123',
        'run_id' => 'run_123',
    ]);
});

it('builds affected subgraph clone commands that preserve the base projection', function () {
    $commands = GenesisGraphImportService::cloneBaseSnapshotCommands(
        'snap_base',
        'snap_new',
        'repo_123',
        'run_123',
    );

    expect($commands)->toHaveCount(9);
    expect($commands[0]['cypher'])->toContain('MERGE (copy:CodeNode:File');
    expect($commands[0]['params'])->toMatchArray([
        'base_snapshot_id' => 'snap_base',
        'snapshot_id' => 'snap_new',
    ]);
    expect(collect($commands)->contains(
        fn (array $command): bool => str_contains($command['cypher'], '[copy:CALLS'),
    ))->toBeTrue();
});

it('builds snapshot-scoped relationship and node deletion commands from tombstone ids', function () {
    $commands = GenesisGraphImportService::deltaDeletionCommands(
        'snap_new',
        ['node:removed'],
        ['rel:removed'],
    );

    expect($commands[0]['cypher'])->toContain('DELETE relationship');
    expect($commands[0]['params']['relationship_ids'])->toBe(['rel:removed']);
    expect($commands[1]['cypher'])->toContain('DETACH DELETE node');
    expect($commands[1]['params']['node_ids'])->toBe(['node:removed']);
});

it('adds snapshot run and repository ids to graph nodes', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'file:app.py',
            'labels' => ['File'],
            'properties' => ['path' => 'app.py'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['params']['properties'])->toMatchArray([
        'snapshot_id' => 'snap_123',
        'run_id' => 'run_123',
        'repository_id' => 'repo_123',
        'path' => 'app.py',
    ]);
});

it('adds run and repository ids to graph relationships', function () {
    $command = GenesisGraphImportService::relationshipCommand(
        [
            'id' => 'rel_1',
            'type' => 'CALLS',
            'source_id' => 'function:a',
            'target_id' => 'function:b',
            'properties' => ['confidence' => 'high'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['params'])->toMatchArray([
        'source_id' => 'function:a',
        'target_id' => 'function:b',
        'type' => 'CALLS',
    ]);
    expect($command['params']['properties'])->toMatchArray([
        'snapshot_id' => 'snap_123',
        'run_id' => 'run_123',
        'repository_id' => 'repo_123',
        'confidence' => 'high',
    ]);
});

it('FakeNeo4jClient records Cypher and parameters for assertion', function () {
    $fake = new FakeNeo4jClient;

    $fake->run('CREATE INDEX code_node_snapshot_external IF NOT EXISTS FOR (n:CodeNode) ON (n.snapshot_id, n.external_id)', []);
    $fake->run('MERGE (s:DevBoardSnapshot {snapshot_id: $snapshot_id}) SET s.repository_id = $repository_id, s.run_id = $run_id', [
        'snapshot_id' => 'snap_1',
        'repository_id' => 'repo_1',
        'run_id' => 'run_1',
    ]);

    expect($fake->commands)->toHaveCount(2);
    expect($fake->commands[0]['cypher'])->toContain('CREATE INDEX code_node_snapshot_external');
    expect($fake->commands[0]['params'])->toBe([]);
    expect($fake->commands[1]['cypher'])->toContain('MERGE (s:DevBoardSnapshot');
    expect($fake->commands[1]['params'])->toMatchArray([
        'snapshot_id' => 'snap_1',
        'repository_id' => 'repo_1',
        'run_id' => 'run_1',
    ]);
});

it('uses :Function label for function node kind', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'function:health',
            'labels' => ['Function'],
            'properties' => ['name' => 'health'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':Function');
    expect($command['cypher'])->not()->toContain('UNWIND $nodes');
});

it('adds semantic labels from analyzer label arrays while preserving CodeNode', function (array $labels, string $expectedLabel) {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'node:test',
            'labels' => $labels,
            'properties' => ['name' => 'test'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain('MERGE (n:CodeNode');
    expect($command['cypher'])->toContain("SET n:{$expectedLabel}");
    expect($command['params']['labels'])->toBe($labels);
})->with([
    'symbol function' => [['Symbol', 'Function'], 'Function'],
    'symbol method' => [['Symbol', 'Method'], 'Function'],
    'symbol class' => [['Symbol', 'Class'], 'Class'],
    'file' => [['File'], 'File'],
]);

it('keeps unknown analyzer symbol labels as only CodeNode', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'variable:x',
            'labels' => ['Symbol', 'Variable'],
            'properties' => ['name' => 'x'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain('MERGE (n:CodeNode');
    expect($command['cypher'])->toContain('SET n:CodeNode');
    expect($command['cypher'])->not()->toContain('SET n:File');
    expect($command['cypher'])->not()->toContain('SET n:Function');
    expect($command['cypher'])->not()->toContain('SET n:Class');
    expect($command['cypher'])->not()->toContain('SET n:Module');
    expect($command['params']['labels'])->toBe(['Symbol', 'Variable']);
});

it('uses :File label for file node kind', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'file:app.py',
            'labels' => ['File'],
            'properties' => ['path' => 'app.py'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':File');
});

it('uses :Class label for class node kind', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'class:Foo',
            'labels' => ['Class'],
            'properties' => ['name' => 'Foo'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':Class');
});

it('uses :Module label for module node kind', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'module:mypkg',
            'labels' => ['Module'],
            'properties' => ['name' => 'mypkg'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':Module');
});

it('uses :CodeNode label for unknown node kind', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'variable:x',
            'labels' => ['Variable'],
            'properties' => ['name' => 'x'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':CodeNode');
});

it('uses :CodeNode label when node has no labels', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'orphan:1',
            'labels' => [],
            'properties' => [],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':CodeNode');
});

it('uses :CodeNode label when node labels key is missing', function () {
    $command = GenesisGraphImportService::nodeCommand(
        [
            'id' => 'unknown_1',
            'properties' => ['name' => 'mystery'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':CodeNode');
});

it('uses :CALLS relationship type for CALLS type', function () {
    $command = GenesisGraphImportService::relationshipCommand(
        [
            'id' => 'rel_1',
            'type' => 'CALLS',
            'source_id' => 'function:a',
            'target_id' => 'function:b',
            'properties' => [],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':CALLS');
    expect($command['cypher'])->not()->toContain(':RELATED');
});

it('uses :DECLARES relationship type for DECLARES type', function () {
    $command = GenesisGraphImportService::relationshipCommand(
        [
            'id' => 'rel_2',
            'type' => 'DECLARES',
            'source_id' => 'file:a',
            'target_id' => 'function:b',
            'properties' => [],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':DECLARES');
    expect($command['cypher'])->not()->toContain(':RELATED');
});

it('uses :IMPORTS relationship type for IMPORTS type', function () {
    $command = GenesisGraphImportService::relationshipCommand(
        [
            'id' => 'rel_3',
            'type' => 'IMPORTS',
            'source_id' => 'file:a',
            'target_id' => 'module:b',
            'properties' => [],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':IMPORTS');
});

it('uses :RELATED relationship type for unknown type', function () {
    $command = GenesisGraphImportService::relationshipCommand(
        [
            'id' => 'rel_4',
            'type' => 'EXTENDS',
            'source_id' => 'class:a',
            'target_id' => 'class:b',
            'properties' => [],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['cypher'])->toContain(':RELATED');
});

it('keeps type property on relationships even with typed relationships', function () {
    $command = GenesisGraphImportService::relationshipCommand(
        [
            'id' => 'rel_5',
            'type' => 'CALLS',
            'source_id' => 'function:a',
            'target_id' => 'function:b',
            'properties' => ['confidence' => 'high'],
        ],
        'snap_123',
        'run_123',
        'repo_123',
    );

    expect($command['params']['type'])->toBe('CALLS');
    expect($command['params']['properties']['confidence'])->toBe('high');
});

it('returns multiple node batch commands grouped by label', function () {
    $nodes = [
        ['id' => 'file:app.py', 'labels' => ['File'], 'properties' => ['path' => 'app.py']],
        ['id' => 'function:health', 'labels' => ['Function'], 'properties' => ['name' => 'health']],
        ['id' => 'function:main', 'labels' => ['Function'], 'properties' => ['name' => 'main']],
        ['id' => 'class:Foo', 'labels' => ['Class'], 'properties' => ['name' => 'Foo']],
    ];

    $commands = GenesisGraphImportService::nodeBatchCommands($nodes, 'snap_123', 'run_123', 'repo_123');

    expect($commands)->toHaveCount(3);

    $cyphers = array_map(fn (array $cmd): string => $cmd['cypher'], $commands);

    expect(collect($cyphers)->first(fn (string $c): bool => str_contains($c, ':File')))->not->toBeNull();
    expect(collect($cyphers)->first(fn (string $c): bool => str_contains($c, ':Function')))->not->toBeNull();
    expect(collect($cyphers)->first(fn (string $c): bool => str_contains($c, ':Class')))->not->toBeNull();
    expect(collect($cyphers)->first(fn (string $c): bool => str_contains($c, 'UNWIND $nodes')))->not->toBeNull();
});

it('groups batch node commands by real analyzer semantic label shapes', function () {
    $nodes = [
        ['id' => 'function:health', 'labels' => ['Symbol', 'Function'], 'properties' => ['name' => 'health']],
        ['id' => 'method:Controller@index', 'labels' => ['Symbol', 'Method'], 'properties' => ['name' => 'index']],
        ['id' => 'class:Controller', 'labels' => ['Symbol', 'Class'], 'properties' => ['name' => 'Controller']],
        ['id' => 'file:app.py', 'labels' => ['File'], 'properties' => ['path' => 'app.py']],
        ['id' => 'variable:x', 'labels' => ['Symbol', 'Variable'], 'properties' => ['name' => 'x']],
    ];

    $commands = GenesisGraphImportService::nodeBatchCommands($nodes, 'snap_123', 'run_123', 'repo_123');

    $commandBySetLabel = collect($commands)->keyBy(function (array $command): string {
        preg_match('/SET n:(File|Function|Class|Module|CodeNode),/', $command['cypher'], $matches);

        return $matches[1] ?? 'missing';
    });

    expect($commandBySetLabel->keys()->all())->toContain('File', 'Function', 'Class', 'CodeNode');
    expect($commandBySetLabel['Function']['params']['nodes'])->toHaveCount(2);
    expect($commandBySetLabel['Class']['params']['nodes'])->toHaveCount(1);
    expect($commandBySetLabel['File']['params']['nodes'])->toHaveCount(1);
    expect($commandBySetLabel['CodeNode']['params']['nodes'])->toHaveCount(1);
    expect($commandBySetLabel['Function']['params']['nodes'][0]['labels'])->toBe(['Symbol', 'Function']);
    expect($commandBySetLabel['CodeNode']['params']['nodes'][0]['labels'])->toBe(['Symbol', 'Variable']);
});

it('returns multiple relationship batch commands grouped by type', function () {
    $relationships = [
        ['id' => 'r1', 'type' => 'CALLS', 'source_id' => 'f:a', 'target_id' => 'f:b', 'properties' => []],
        ['id' => 'r2', 'type' => 'DECLARES', 'source_id' => 'file:x', 'target_id' => 'f:y', 'properties' => []],
        ['id' => 'r3', 'type' => 'CALLS', 'source_id' => 'f:c', 'target_id' => 'f:d', 'properties' => []],
        ['id' => 'r4', 'type' => 'IMPORTS', 'source_id' => 'file:m', 'target_id' => 'module:n', 'properties' => []],
    ];

    $commands = GenesisGraphImportService::relationshipBatchCommands($relationships, 'snap_123', 'run_123', 'repo_123');

    expect($commands)->toHaveCount(3);

    $cyphers = array_map(fn (array $cmd): string => $cmd['cypher'], $commands);

    expect(collect($cyphers)->first(fn (string $c): bool => str_contains($c, ':CALLS')))->not->toBeNull();
    expect(collect($cyphers)->first(fn (string $c): bool => str_contains($c, ':DECLARES')))->not->toBeNull();
    expect(collect($cyphers)->first(fn (string $c): bool => str_contains($c, ':IMPORTS')))->not->toBeNull();
});

it('uses [:RELATED] for unknown relationship types in batch commands', function () {
    $relationships = [
        ['id' => 'r1', 'type' => 'EXTENDS', 'source_id' => 'c:a', 'target_id' => 'c:b', 'properties' => []],
    ];

    $commands = GenesisGraphImportService::relationshipBatchCommands($relationships, 'snap_123', 'run_123', 'repo_123');

    expect($commands[0]['cypher'])->toContain(':RELATED');
});
