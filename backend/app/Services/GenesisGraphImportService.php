<?php

namespace App\Services;

use App\Services\Neo4j\Neo4jClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GenesisGraphImportService
{
    private const BATCH_SIZE = 500;

    /**
     * @return list<array{cypher: string, params: array<string, mixed>}>
     */
    public static function indexCommands(): array
    {
        return [
            [
                'cypher' => 'CREATE INDEX code_node_snapshot_external IF NOT EXISTS FOR (n:CodeNode) ON (n.snapshot_id, n.external_id)',
                'params' => [],
            ],
            [
                'cypher' => 'CREATE INDEX devboard_snapshot_snapshot_id IF NOT EXISTS FOR (s:DevBoardSnapshot) ON (s.snapshot_id)',
                'params' => [],
            ],
            [
                'cypher' => 'CALL db.awaitIndexes(300)',
                'params' => [],
            ],
        ];
    }

    /**
     * @return array{cypher: string, params: array<string, mixed>}
     */
    public static function devBoardSnapshotCommand(string $snapshotId, string $repositoryId, string $runId): array
    {
        return [
            'cypher' => 'MERGE (s:DevBoardSnapshot {snapshot_id: $snapshot_id}) SET s.repository_id = $repository_id, s.run_id = $run_id',
            'params' => [
                'snapshot_id' => $snapshotId,
                'repository_id' => $repositoryId,
                'run_id' => $runId,
            ],
        ];
    }

    /**
     * @return array{cypher: string, params: array<string, mixed>}
     */
    public static function devBoardDeltaSnapshotCommand(
        string $snapshotId,
        string $baseSnapshotId,
        string $repositoryId,
        string $runId,
        string $deltaId,
    ): array {
        return [
            'cypher' => 'MERGE (s:DevBoardSnapshot {snapshot_id: $snapshot_id}) SET s.repository_id = $repository_id, s.run_id = $run_id, s.base_snapshot_id = $base_snapshot_id, s.delta_sync_id = $delta_id',
            'params' => [
                'snapshot_id' => $snapshotId,
                'base_snapshot_id' => $baseSnapshotId,
                'repository_id' => $repositoryId,
                'run_id' => $runId,
                'delta_id' => $deltaId,
            ],
        ];
    }

    /**
     * @return list<array{cypher: string, params: array<string, mixed>}>
     */
    public static function cloneBaseSnapshotCommands(
        string $baseSnapshotId,
        string $snapshotId,
        string $repositoryId,
        string $runId,
    ): array {
        $params = [
            'base_snapshot_id' => $baseSnapshotId,
            'snapshot_id' => $snapshotId,
            'repository_id' => $repositoryId,
            'run_id' => $runId,
        ];
        $commands = [];

        foreach (['File', 'Function', 'Class', 'Module'] as $label) {
            $commands[] = [
                'cypher' => "MATCH (source:CodeNode:{$label} {snapshot_id: \$base_snapshot_id, repository_id: \$repository_id}) MERGE (copy:CodeNode:{$label} {external_id: source.external_id, snapshot_id: \$snapshot_id}) SET copy = properties(source), copy.snapshot_id = \$snapshot_id, copy.run_id = \$run_id",
                'params' => $params,
            ];
        }

        $commands[] = [
            'cypher' => 'MATCH (source:CodeNode {snapshot_id: $base_snapshot_id, repository_id: $repository_id}) WHERE NOT source:File AND NOT source:Function AND NOT source:Class AND NOT source:Module MERGE (copy:CodeNode {external_id: source.external_id, snapshot_id: $snapshot_id}) SET copy = properties(source), copy.snapshot_id = $snapshot_id, copy.run_id = $run_id',
            'params' => $params,
        ];

        foreach (['CALLS', 'DECLARES', 'IMPORTS', 'RELATED'] as $type) {
            $commands[] = [
                'cypher' => "MATCH (source:CodeNode {snapshot_id: \$base_snapshot_id})-[relationship:{$type}]->(target:CodeNode {snapshot_id: \$base_snapshot_id}) MATCH (new_source:CodeNode {external_id: source.external_id, snapshot_id: \$snapshot_id}), (new_target:CodeNode {external_id: target.external_id, snapshot_id: \$snapshot_id}) MERGE (new_source)-[copy:{$type} {external_id: relationship.external_id, snapshot_id: \$snapshot_id}]->(new_target) SET copy = properties(relationship), copy.snapshot_id = \$snapshot_id, copy.run_id = \$run_id",
                'params' => $params,
            ];
        }

        return $commands;
    }

    /**
     * @param  list<string>  $nodeIds
     * @param  list<string>  $relationshipIds
     * @return list<array{cypher: string, params: array<string, mixed>}>
     */
    public static function deltaDeletionCommands(string $snapshotId, array $nodeIds, array $relationshipIds): array
    {
        return [
            [
                'cypher' => 'MATCH ()-[relationship {snapshot_id: $snapshot_id}]->() WHERE relationship.external_id IN $relationship_ids DELETE relationship',
                'params' => ['snapshot_id' => $snapshotId, 'relationship_ids' => $relationshipIds],
            ],
            [
                'cypher' => 'MATCH (node:CodeNode {snapshot_id: $snapshot_id}) WHERE node.external_id IN $node_ids DETACH DELETE node',
                'params' => ['snapshot_id' => $snapshotId, 'node_ids' => $nodeIds],
            ],
        ];
    }

    /**
     * @param  list<string>  $labels
     */
    private static function nodeLabelForLabels(array $labels): string
    {
        $first = strtolower($labels[0] ?? '');

        return match ($first) {
            'function', 'method' => 'Function',
            'class' => 'Class',
            'file' => 'File',
            'module' => 'Module',
            default => 'CodeNode',
        };
    }

    private static function relationshipTypeForType(string $type): string
    {
        $upper = strtoupper($type);

        return match ($upper) {
            'CALLS' => 'CALLS',
            'DECLARES' => 'DECLARES',
            'IMPORTS' => 'IMPORTS',
            default => 'RELATED',
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{cypher: string, params: array<string, mixed>}
     */
    public static function nodeCommand(array $node, string $snapshotId, string $runId, string $repositoryId): array
    {
        $properties = array_merge($node['properties'] ?? [], [
            'snapshot_id' => $snapshotId,
            'run_id' => $runId,
            'repository_id' => $repositoryId,
        ]);

        $label = self::nodeLabelForLabels($node['labels'] ?? []);

        return [
            'cypher' => "MERGE (n:CodeNode {external_id: \$id, snapshot_id: \$snapshot_id}) SET n:{$label}, n += \$properties, n.labels = \$labels",
            'params' => [
                'id' => $node['id'],
                'snapshot_id' => $snapshotId,
                'labels' => $node['labels'] ?? [],
                'properties' => $properties,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $relationship
     * @return array{cypher: string, params: array<string, mixed>}
     */
    public static function relationshipCommand(array $relationship, string $snapshotId, string $runId, string $repositoryId): array
    {
        $properties = array_merge($relationship['properties'] ?? [], [
            'snapshot_id' => $snapshotId,
            'run_id' => $runId,
            'repository_id' => $repositoryId,
        ]);

        $relType = self::relationshipTypeForType($relationship['type']);

        return [
            'cypher' => "MATCH (source:CodeNode {external_id: \$source_id, snapshot_id: \$snapshot_id}) MATCH (target:CodeNode {external_id: \$target_id, snapshot_id: \$snapshot_id}) MERGE (source)-[r:{$relType} {external_id: \$id, snapshot_id: \$snapshot_id}]->(target) SET r.type = \$type, r += \$properties",
            'params' => [
                'id' => $relationship['id'] ?? (string) Str::ulid(),
                'source_id' => $relationship['source_id'] ?? $relationship['source_symbol_id'],
                'target_id' => $relationship['target_id'] ?? $relationship['target_symbol_id'],
                'type' => $relationship['type'],
                'snapshot_id' => $snapshotId,
                'properties' => $properties,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{cypher: string, params: array<string, mixed>}>
     */
    public static function nodeBatchCommands(array $nodes, string $snapshotId, string $runId, string $repositoryId): array
    {
        $byLabel = [];

        foreach ($nodes as $node) {
            $label = self::nodeLabelForLabels($node['labels'] ?? []);
            $byLabel[$label][] = [
                'id' => $node['id'],
                'labels' => $node['labels'] ?? [],
                'properties' => array_merge($node['properties'] ?? [], [
                    'snapshot_id' => $snapshotId,
                    'run_id' => $runId,
                    'repository_id' => $repositoryId,
                ]),
            ];
        }

        $commands = [];

        foreach ($byLabel as $label => $groupedNodes) {
            $commands[] = [
                'cypher' => "UNWIND \$nodes AS node MERGE (n:CodeNode {external_id: node.id, snapshot_id: \$snapshot_id}) REMOVE n:File:Function:Class:Module SET n:{$label}, n = node.properties, n.external_id = node.id, n.labels = node.labels",
                'params' => [
                    'snapshot_id' => $snapshotId,
                    'nodes' => $groupedNodes,
                ],
            ];
        }

        return $commands;
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     * @return list<array{cypher: string, params: array<string, mixed>}>
     */
    public static function relationshipBatchCommands(array $relationships, string $snapshotId, string $runId, string $repositoryId): array
    {
        $byType = [];

        foreach ($relationships as $relationship) {
            $type = self::relationshipTypeForType($relationship['type']);
            $byType[$type][] = [
                'id' => $relationship['id'] ?? (string) Str::ulid(),
                'source_id' => $relationship['source_id'] ?? $relationship['source_symbol_id'],
                'target_id' => $relationship['target_id'] ?? $relationship['target_symbol_id'],
                'type' => $relationship['type'],
                'properties' => array_merge($relationship['properties'] ?? [], [
                    'snapshot_id' => $snapshotId,
                    'run_id' => $runId,
                    'repository_id' => $repositoryId,
                ]),
            ];
        }

        $commands = [];

        foreach ($byType as $relType => $groupedRelationships) {
            $commands[] = [
                'cypher' => "UNWIND \$relationships AS relationship MATCH (source:CodeNode {external_id: relationship.source_id, snapshot_id: \$snapshot_id}) MATCH (target:CodeNode {external_id: relationship.target_id, snapshot_id: \$snapshot_id}) MERGE (source)-[r:{$relType} {external_id: relationship.id, snapshot_id: \$snapshot_id}]->(target) SET r = relationship.properties, r.external_id = relationship.id, r.type = relationship.type",
                'params' => [
                    'snapshot_id' => $snapshotId,
                    'relationships' => $groupedRelationships,
                ],
            ];
        }

        return $commands;
    }

    public function importGenesis(
        string $importId,
        ?Neo4jClient $client = null,
        string $mode = 'neo4j',
        bool $markFailedOnException = true,
    ): void {
        $client ??= app(Neo4jClientFactory::class)->client();
        $import = DB::table('genesis_imports')->where('id', $importId)->first();
        if (! $import || ! $import->snapshot_id) {
            throw new RuntimeException('Genesis import is not ready for graph import.');
        }

        $snapshot = DB::table('snapshots')->where('id', $import->snapshot_id)->first();
        if (! $snapshot || ! $snapshot->graph_snapshot_artifact_id) {
            throw new RuntimeException('Genesis snapshot does not reference a graph artifact.');
        }

        try {
            $this->importGraphArtifact(
                $snapshot->id,
                $import->repository_id,
                $import->run_id,
                $snapshot->graph_snapshot_artifact_id,
                $client,
                $mode,
                'Genesis graph import validated in fake mode.',
                'Genesis graph imported into Neo4j.',
            );
        } catch (\Throwable $exception) {
            if ($markFailedOnException) {
                DB::table('genesis_imports')->where('id', $importId)->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            }

            throw $exception;
        }
    }

    public function importDelta(
        string $deltaId,
        ?Neo4jClient $client = null,
        string $mode = 'neo4j',
    ): void {
        $delta = DB::table('delta_syncs')->where('id', $deltaId)->first();
        if (! $delta || ! $delta->new_snapshot_id) {
            throw new RuntimeException('Delta sync is not ready for graph import.');
        }

        $snapshot = DB::table('snapshots')->where('id', $delta->new_snapshot_id)->first();
        if (! $snapshot || ! $snapshot->graph_snapshot_artifact_id) {
            throw new RuntimeException('Delta snapshot does not reference a graph artifact.');
        }

        $this->importGraphArtifact(
            $snapshot->id,
            $delta->repository_id,
            $delta->run_id,
            $snapshot->graph_snapshot_artifact_id,
            $client,
            $mode,
            'Delta graph import validated in fake mode.',
            'Delta graph imported into Neo4j.',
            $delta->base_snapshot_id,
            $deltaId,
        );
    }

    public function importGraphArtifact(
        string $snapshotId,
        string $repositoryId,
        string $runId,
        string $artifactId,
        ?Neo4jClient $client = null,
        string $mode = 'neo4j',
        string $fakeMessage = 'Graph import validated in fake mode.',
        string $neo4jMessage = 'Graph imported into Neo4j.',
        ?string $baseSnapshotId = null,
        ?string $deltaId = null,
        bool $force = false,
    ): void {
        $client ??= app(Neo4jClientFactory::class)->client();
        $artifact = DB::table('artifacts')->where('id', $artifactId)->first();
        if (! $artifact) {
            throw new RuntimeException('Graph snapshot artifact was not found.');
        }

        if (! $force && $this->alreadyImported($snapshotId, $runId)) {
            return;
        }

        $graph = json_decode(Storage::disk('local')->get($artifact->storage_path), true, 512, JSON_THROW_ON_ERROR);

        $this->ensureIndexes($client);
        $graphMode = (string) ($graph['graph_mode'] ?? 'full_snapshot');
        $nodes = is_array($graph['nodes_upserted'] ?? null)
            ? $graph['nodes_upserted']
            : ($graph['nodes'] ?? []);
        $relationships = is_array($graph['relationships_upserted'] ?? null)
            ? $graph['relationships_upserted']
            : ($graph['relationships'] ?? []);

        if ($graphMode === 'affected_subgraph') {
            if ($baseSnapshotId === null || $deltaId === null) {
                throw new RuntimeException('Affected subgraph import requires a base snapshot and delta sync.');
            }
            if (isset($graph['base_snapshot_id']) && (string) $graph['base_snapshot_id'] !== $baseSnapshotId) {
                throw new RuntimeException('Affected subgraph base snapshot does not match the Delta sync.');
            }

            $this->runCommand($client, self::devBoardDeltaSnapshotCommand(
                $snapshotId,
                $baseSnapshotId,
                $repositoryId,
                $runId,
                $deltaId,
            ));
            foreach (self::cloneBaseSnapshotCommands($baseSnapshotId, $snapshotId, $repositoryId, $runId) as $command) {
                $this->runCommand($client, $command);
            }

            $nodeIds = $this->tombstoneIds($graph['nodes_deleted'] ?? [], 'nodes_deleted');
            $relationshipIds = $this->tombstoneIds($graph['relationships_deleted'] ?? [], 'relationships_deleted');
            foreach ($relationships as $relationship) {
                if (is_array($relationship) && isset($relationship['id']) && is_string($relationship['id'])) {
                    $relationshipIds[] = $relationship['id'];
                }
            }
            $relationshipIds = array_values(array_unique($relationshipIds));
            foreach (self::deltaDeletionCommands($snapshotId, $nodeIds, $relationshipIds) as $command) {
                $this->runCommand($client, $command);
            }
        } else {
            $this->runCommand($client, self::devBoardSnapshotCommand($snapshotId, $repositoryId, $runId));
        }

        foreach (array_chunk($nodes, self::BATCH_SIZE) as $nodeBatch) {
            if ($nodeBatch !== []) {
                foreach (self::nodeBatchCommands($nodeBatch, $snapshotId, $runId, $repositoryId) as $cmd) {
                    $this->runCommand($client, $cmd);
                }
            }
        }

        foreach (array_chunk($relationships, self::BATCH_SIZE) as $relationshipBatch) {
            if ($relationshipBatch !== []) {
                foreach (self::relationshipBatchCommands($relationshipBatch, $snapshotId, $runId, $repositoryId) as $cmd) {
                    $this->runCommand($client, $cmd);
                }
            }
        }

        DB::table('artifacts')->where('id', $artifactId)->update([
            'status' => 'imported',
            'updated_at' => now(),
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $runId,
            'event_type' => 'graph.imported',
            'severity' => 'info',
            'message' => $mode === 'fake' ? $fakeMessage : $neo4jMessage,
            'payload' => json_encode(['snapshot_id' => $snapshotId, 'mode' => $mode], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array{cypher: string, params: array<string, mixed>}  $command
     */
    private function runCommand(Neo4jClient $client, array $command): void
    {
        $client->run($command['cypher'], $command['params']);
    }

    private function ensureIndexes(Neo4jClient $client): void
    {
        foreach (self::indexCommands() as $command) {
            $this->runCommand($client, $command);
        }
    }

    private function alreadyImported(string $snapshotId, string $runId): bool
    {
        $payloads = DB::table('run_events')
            ->where('run_id', $runId)
            ->where('event_type', 'graph.imported')
            ->pluck('payload');

        foreach ($payloads as $payload) {
            if (! is_string($payload)) {
                continue;
            }

            try {
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_array($decoded) && ($decoded['snapshot_id'] ?? null) === $snapshotId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function tombstoneIds(mixed $tombstones, string $field): array
    {
        if (! is_array($tombstones) || ! array_is_list($tombstones)) {
            throw new RuntimeException("{$field} must be a list of identifiers or tombstones.");
        }

        $ids = [];
        foreach ($tombstones as $tombstone) {
            $id = is_string($tombstone)
                ? $tombstone
                : (is_array($tombstone)
                    ? ($tombstone['id'] ?? $tombstone['external_id'] ?? $tombstone['node_id'] ?? $tombstone['relationship_id'] ?? null)
                    : null);

            if (! is_string($id) || $id === '') {
                throw new RuntimeException("{$field} contains an invalid tombstone.");
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
