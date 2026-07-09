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
    $fake = new FakeNeo4jClient();

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
