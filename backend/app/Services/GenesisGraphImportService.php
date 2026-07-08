<?php

namespace App\Services;

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
     * @param array<string, mixed> $node
     * @return array{cypher: string, params: array<string, mixed>}
     */
    public static function nodeCommand(array $node, string $snapshotId, string $runId, string $repositoryId): array
    {
        $properties = array_merge($node['properties'] ?? [], [
            'snapshot_id' => $snapshotId,
            'run_id' => $runId,
            'repository_id' => $repositoryId,
        ]);

        return [
            'cypher' => 'MERGE (n:CodeNode {external_id: $id, snapshot_id: $snapshot_id}) SET n += $properties, n.labels = $labels',
            'params' => [
                'id' => $node['id'],
                'snapshot_id' => $snapshotId,
                'labels' => $node['labels'] ?? [],
                'properties' => $properties,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $relationship
     * @return array{cypher: string, params: array<string, mixed>}
     */
    public static function relationshipCommand(array $relationship, string $snapshotId, string $runId, string $repositoryId): array
    {
        $properties = array_merge($relationship['properties'] ?? [], [
            'snapshot_id' => $snapshotId,
            'run_id' => $runId,
            'repository_id' => $repositoryId,
        ]);

        return [
            'cypher' => 'MATCH (source:CodeNode {external_id: $source_id, snapshot_id: $snapshot_id}) MATCH (target:CodeNode {external_id: $target_id, snapshot_id: $snapshot_id}) MERGE (source)-[r:RELATED {external_id: $id, snapshot_id: $snapshot_id}]->(target) SET r.type = $type, r += $properties',
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
     * @param list<array<string, mixed>> $nodes
     * @return array{cypher: string, params: array<string, mixed>}
     */
    public static function nodeBatchCommand(array $nodes, string $snapshotId, string $runId, string $repositoryId): array
    {
        return [
            'cypher' => 'UNWIND $nodes AS node MERGE (n:CodeNode {external_id: node.id, snapshot_id: $snapshot_id}) SET n += node.properties, n.labels = node.labels',
            'params' => [
                'snapshot_id' => $snapshotId,
                'nodes' => array_map(
                    static fn (array $node): array => [
                        'id' => $node['id'],
                        'labels' => $node['labels'] ?? [],
                        'properties' => array_merge($node['properties'] ?? [], [
                            'snapshot_id' => $snapshotId,
                            'run_id' => $runId,
                            'repository_id' => $repositoryId,
                        ]),
                    ],
                    $nodes,
                ),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @return array{cypher: string, params: array<string, mixed>}
     */
    public static function relationshipBatchCommand(array $relationships, string $snapshotId, string $runId, string $repositoryId): array
    {
        return [
            'cypher' => 'UNWIND $relationships AS relationship MATCH (source:CodeNode {external_id: relationship.source_id, snapshot_id: $snapshot_id}) MATCH (target:CodeNode {external_id: relationship.target_id, snapshot_id: $snapshot_id}) MERGE (source)-[r:RELATED {external_id: relationship.id, snapshot_id: $snapshot_id}]->(target) SET r.type = relationship.type, r += relationship.properties',
            'params' => [
                'snapshot_id' => $snapshotId,
                'relationships' => array_map(
                    static fn (array $relationship): array => [
                        'id' => $relationship['id'] ?? (string) Str::ulid(),
                        'source_id' => $relationship['source_id'] ?? $relationship['source_symbol_id'],
                        'target_id' => $relationship['target_id'] ?? $relationship['target_symbol_id'],
                        'type' => $relationship['type'],
                        'properties' => array_merge($relationship['properties'] ?? [], [
                            'snapshot_id' => $snapshotId,
                            'run_id' => $runId,
                            'repository_id' => $repositoryId,
                        ]),
                    ],
                    $relationships,
                ),
            ],
        ];
    }

    public function importGenesis(
        string $importId,
        ?object $client = null,
        string $mode = 'neo4j',
        bool $markFailedOnException = true,
    ): void
    {
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

    public function importGraphArtifact(
        string $snapshotId,
        string $repositoryId,
        string $runId,
        string $artifactId,
        ?object $client = null,
        string $mode = 'neo4j',
        string $fakeMessage = 'Graph import validated in fake mode.',
        string $neo4jMessage = 'Graph imported into Neo4j.',
    ): void {
        $client ??= app(Neo4jClientFactory::class)->client();
        $artifact = DB::table('artifacts')->where('id', $artifactId)->first();
        if (! $artifact) {
            throw new RuntimeException('Graph snapshot artifact was not found.');
        }

        $graph = json_decode(Storage::disk('local')->get($artifact->storage_path), true, 512, JSON_THROW_ON_ERROR);

        $this->ensureIndexes($client);
        $this->runCommand($client, self::devBoardSnapshotCommand($snapshotId, $repositoryId, $runId));

        foreach (array_chunk($graph['nodes'] ?? [], self::BATCH_SIZE) as $nodes) {
            if ($nodes !== []) {
                $this->runCommand($client, self::nodeBatchCommand($nodes, $snapshotId, $runId, $repositoryId));
            }
        }

        foreach (array_chunk($graph['relationships'] ?? [], self::BATCH_SIZE) as $relationships) {
            if ($relationships !== []) {
                $this->runCommand($client, self::relationshipBatchCommand($relationships, $snapshotId, $runId, $repositoryId));
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
     * @param array{cypher: string, params: array<string, mixed>} $command
     */
    private function runCommand(object $client, array $command): void
    {
        $client->run($command['cypher'], $command['params']);
    }

    private function ensureIndexes(object $client): void
    {
        foreach (self::indexCommands() as $command) {
            $this->runCommand($client, $command);
        }
    }
}
