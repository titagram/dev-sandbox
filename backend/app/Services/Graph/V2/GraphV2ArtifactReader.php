<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImport;
use App\Services\ArtifactStorageService;

final class GraphV2ArtifactReader implements GraphV2ArtifactReaderContract
{
    public const int BATCH_SIZE = 1000;

    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly GraphV2StoredChunkReaderContract $chunks,
    ) {}

    /**
     * Yield bounded record batches in the manifest's canonical chunk order.
     *
     * @return Generator<int, array{kind:string,index:int,records:list<\stdClass>},void,void>
     */
    public function batches(HadesGraphImport $import): iterable
    {
        $manifest = $import->manifest;
        $descriptors = $import->manifest['chunks'] ?? [];
        if (! is_array($descriptors)) {
            throw new GraphV2ImportException('graph_validation_manifest_invalid', 'Stored graph manifest chunks are invalid.');
        }
        $lastKind = -1;
        $totals = array_fill_keys(['entrypoints', 'nodes', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties'], 0);
        foreach ($descriptors as $position => $descriptor) {
            if (! is_array($descriptor)
                || ($descriptor['index'] ?? null) !== $position
                || ! array_key_exists($descriptor['kind'] ?? '', $totals)) {
                throw new GraphV2ImportException('graph_validation_chunk_order_invalid', 'Stored graph manifest chunk order is invalid.');
            }
            $kind = array_search($descriptor['kind'], array_keys($totals), true);
            if (! is_int($kind) || $kind < $lastKind) {
                throw new GraphV2ImportException('graph_validation_chunk_order_invalid', 'Stored graph manifest kind order is invalid.');
            }
            $lastKind = $kind;
            $totals[$descriptor['kind']] += (int) ($descriptor['record_count'] ?? 0);
        }
        foreach ($totals as $kind => $total) {
            if ($total !== (int) ($manifest['counts'][$kind] ?? 0)) {
                throw new GraphV2ImportException('graph_validation_count_mismatch', 'Stored graph manifest counts do not match its chunk descriptors.');
            }
        }

        $this->assertManifestIdentity($import, $descriptors);
        $chunkIndexes = array_map(static fn (array $descriptor): int => (int) $descriptor['index'], $descriptors);
        $storedChunks = $import->chunks()
            ->whereIn('chunk_index', $chunkIndexes)
            ->orderBy('chunk_index')
            ->get()
            ->keyBy('chunk_index');
        if ($storedChunks->count() !== count($descriptors)) {
            throw new GraphV2ImportException('graph_validation_chunk_missing', 'A declared graph chunk is not stored.');
        }

        foreach ($descriptors as $descriptor) {
            $index = (int) $descriptor['index'];
            $row = $storedChunks->get($index);
            if ($row === null
                || (string) $row->kind !== (string) $descriptor['kind']
                || (int) $row->record_count !== (int) $descriptor['record_count']
                || (string) $row->sha256 !== (string) $descriptor['sha256']
                || (int) $row->uncompressed_bytes !== (int) $descriptor['uncompressed_bytes']
                || (string) $row->compression !== (string) $descriptor['compression']
                || (string) $row->compressed_sha256 !== (string) $descriptor['compressed_sha256']
                || (int) $row->compressed_bytes !== (int) $descriptor['compressed_bytes']) {
                throw new GraphV2ImportException('graph_validation_chunk_identity_mismatch', 'Stored graph chunk metadata does not match its manifest descriptor.');
            }
        }

        foreach ($descriptors as $descriptor) {
            if (! is_array($descriptor)) {
                throw new GraphV2ImportException('graph_validation_manifest_invalid', 'Stored graph chunk descriptor is invalid.');
            }

            $index = (int) ($descriptor['index'] ?? -1);
            $row = $storedChunks->get($index);
            if ($row === null) {
                throw new GraphV2ImportException('graph_validation_chunk_missing', 'A declared graph chunk is not stored.');
            }

            try {
                $stream = $this->storage->readGraphChunkStream((string) $row->storage_disk, (string) $row->storage_path);
            } catch (GraphV2ImportException|GraphV2InfrastructureException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new GraphV2InfrastructureException('Graph artifact storage could not be read.', 0, $exception);
            }
            if (! is_resource($stream)) {
                throw new GraphV2InfrastructureException('Graph artifact storage returned an invalid stream.');
            }
            try {
                $batch = [];
                foreach ($this->chunks->streamRecords($import, $index, $stream, [
                    'Content-Type' => 'application/vnd.hades.graph-chunk+gzip',
                    'X-Hades-Chunk-Sha256' => (string) $row->sha256,
                    'X-Hades-Chunk-Uncompressed-Bytes' => (string) $row->uncompressed_bytes,
                    'X-Hades-Chunk-Compressed-Sha256' => (string) $row->compressed_sha256,
                    'X-Hades-Chunk-Compressed-Bytes' => (string) $row->compressed_bytes,
                ], $descriptor) as $record) {
                    $batch[] = $record;
                    if (count($batch) === self::BATCH_SIZE) {
                        yield ['kind' => (string) $descriptor['kind'], 'index' => $index, 'records' => $batch];
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    yield ['kind' => (string) $descriptor['kind'], 'index' => $index, 'records' => $batch];
                }
            } finally {
                fclose($stream);
            }
        }
    }

    private function assertManifestIdentity(HadesGraphImport $import, mixed $descriptors): void
    {
        $manifest = $import->manifest;
        if (($manifest['schema'] ?? null) !== 'hades.graph_bundle.v2'
            || ($manifest['artifact_schema'] ?? null) !== 'hades.code_graph.v2'
            || ($manifest['project']['project_id'] ?? null) !== $import->project_id
            || ($manifest['project']['workspace_binding_id'] ?? null) !== $import->workspace_binding_id
            || ($manifest['artifact_graph_version'] ?? null) !== $import->artifact_graph_version
            || ($manifest['source'] ?? null) !== $import->source_identity) {
            throw new GraphV2ImportException('graph_validation_identity_mismatch', 'Stored graph artifact identity does not match the import.');
        }
        if (! is_array($descriptors)) {
            throw new GraphV2ImportException('graph_validation_manifest_invalid', 'Stored graph manifest chunks are invalid.');
        }
    }
}
