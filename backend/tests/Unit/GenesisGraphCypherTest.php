<?php

use App\Services\GenesisGraphImportService;

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
