<?php

namespace App\Services\Graph\V2;

use App\Models\HadesAgent;
use App\Models\HadesGraphImport;
use App\Models\HadesGraphImportChunk;
use App\Models\HadesWorkspaceBinding;
use App\Models\Project;
use App\Services\ArtifactStorageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GraphV2ImportService
{
    public function __construct(
        private readonly GraphV2ManifestValidator $manifests,
        private readonly GraphV2ChunkValidator $chunks,
        private readonly ArtifactStorageService $storage,
    ) {}

    /** @param array<string, mixed> $manifest @return array{status_code:int,payload:array<string,mixed>} */
    public function create(Project $project, HadesWorkspaceBinding $binding, HadesAgent $agent, array $manifest): array
    {
        $validated = $this->manifests->validate($manifest, $project, $binding);
        $artifactVersion = (string) $manifest['artifact_graph_version'];
        $now = now();

        return DB::transaction(function () use ($agent, $artifactVersion, $binding, $manifest, $now, $project, $validated): array {
            HadesWorkspaceBinding::query()->whereKey($binding->id)->lockForUpdate()->firstOrFail();
            $base = HadesGraphImport::query()
                ->where('project_id', $project->id)
                ->where('workspace_binding_id', $binding->id)
                ->where('artifact_graph_version', $artifactVersion);
            $live = (clone $base)->whereIn('status', HadesGraphImport::LIVE_STATUSES)->lockForUpdate()->first();
            if ($live && $live->status === HadesGraphImport::STATUS_STAGING && $this->expired($live)) {
                $this->markStale($live);
                $live = null;
            }
            if ($live) {
                if (! hash_equals($live->manifest_semantic_sha256, $validated['semantic_sha256'])) {
                    throw new GraphV2ImportException('graph_import_manifest_conflict', 'A live import already exists for a different manifest.', 409);
                }

                return $this->result($live, 200);
            }
            $validatedImport = (clone $base)->where('status', HadesGraphImport::STATUS_VALIDATED)->lockForUpdate()->first();
            if ($validatedImport) {
                return $this->result($validatedImport, 200);
            }
            $generation = ((int) (clone $base)->max('attempt_generation')) + 1;
            $import = HadesGraphImport::query()->create([
                'id' => (string) Str::ulid(), 'project_id' => $project->id, 'workspace_binding_id' => $binding->id,
                'hades_agent_id' => $agent->id, 'attempt_generation' => $generation, 'schema' => 'hades.code_graph.v2',
                'artifact_graph_version' => $artifactVersion, 'manifest_semantic_sha256' => $validated['semantic_sha256'],
                'source_identity' => $manifest['source'], 'manifest' => $manifest, 'status' => HadesGraphImport::STATUS_STAGING,
                'completeness_status' => $validated['completeness_status'], 'expected_chunks' => $validated['expected_chunks'],
                'received_chunks' => 0, 'expected_uncompressed_bytes' => $validated['expected_uncompressed_bytes'],
                'received_uncompressed_bytes' => 0, 'expected_compressed_bytes' => $validated['expected_compressed_bytes'],
                'received_compressed_bytes' => 0, 'validation_attempts' => 0,
                'expires_at' => $now->copy()->addHours((int) config('devboard.artifacts.incomplete_upload_ttl_hours', 24)),
            ]);

            return $this->result($import, 201);
        });
    }

    /** @param resource $body @param array<string, string> $headers @return array{status_code:int,payload:array<string,mixed>} */
    public function putChunk(HadesGraphImport $import, int $index, $body, array $headers): array
    {
        $expired = DB::transaction(function () use ($import): bool {
            $locked = HadesGraphImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === HadesGraphImport::STATUS_STAGING && $this->expired($locked)) {
                $this->markStale($locked);

                return true;
            }
            $this->ensureStaging($locked);

            return false;
        });
        if ($expired) {
            throw new GraphV2ImportException('graph_import_stale', 'Graph import is stale.', 410);
        }
        $descriptor = $this->descriptor($import, $index);
        $existingBeforeValidation = HadesGraphImportChunk::query()->where('graph_import_id', $import->id)->where('chunk_index', $index)->first();
        $preparedBody = null;
        if ($existingBeforeValidation) {
            try {
                $fingerprint = $this->chunks->fingerprint($body, (int) $existingBeforeValidation->compressed_bytes);
            } catch (GraphV2ImportException $exception) {
                if ($exception->errorCode === 'graph_chunk_too_large') {
                    throw new GraphV2ImportException('chunk_digest_conflict', 'Chunk index was already uploaded with different bytes or digest.', 409);
                }

                throw $exception;
            }
            if ($fingerprint['sha256'] !== $existingBeforeValidation->compressed_sha256 || $fingerprint['bytes'] !== (int) $existingBeforeValidation->compressed_bytes) {
                fclose($fingerprint['stream']);
                throw new GraphV2ImportException('chunk_digest_conflict', 'Chunk index was already uploaded with different bytes or digest.', 409);
            }
            $body = $fingerprint['stream'];
            $preparedBody = $body;
        }
        try {
            $validated = $this->chunks->validate($import, $index, $body, $headers, $descriptor);
        } finally {
            if (is_resource($preparedBody)) {
                fclose($preparedBody);
            }
        }
        $path = null;
        $storageDisk = (string) config('devboard.artifacts.disk', 'local');
        $committed = false;
        $stale = false;
        try {
            $result = DB::transaction(function () use (&$path, &$stale, $descriptor, $import, $index, $storageDisk, $validated): array {
                $locked = HadesGraphImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === HadesGraphImport::STATUS_STAGING && $this->expired($locked)) {
                    $this->markStale($locked);
                    $stale = true;

                    return ['status_code' => 0, 'payload' => []];
                }
                $this->ensureStaging($locked);
                $existing = HadesGraphImportChunk::query()->where('graph_import_id', $locked->id)->where('chunk_index', $index)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->compressed_sha256 !== $descriptor['compressed_sha256'] || (int) $existing->compressed_bytes !== (int) $descriptor['compressed_bytes']) {
                        throw new GraphV2ImportException('chunk_digest_conflict', 'Chunk index was already uploaded with different bytes or digest.', 409);
                    }
                    $locked->update(['expires_at' => now()->addHours((int) config('devboard.artifacts.incomplete_upload_ttl_hours', 24))]);

                    return ['status_code' => 200, 'payload' => ['index' => $index, 'status' => 'accepted']];
                }
                $this->assertAdjacentOrder($locked, $index, $descriptor, $validated['first_id'], $validated['last_id']);
                $path = $this->storage->storeGraphChunk($locked->id, $index, $validated['compressed']);
                $now = now();
                HadesGraphImportChunk::query()->create([
                    'id' => (string) Str::ulid(), 'graph_import_id' => $locked->id, 'chunk_index' => $index,
                    'kind' => $descriptor['kind'], 'sha256' => $descriptor['sha256'], 'record_count' => $descriptor['record_count'],
                    'uncompressed_bytes' => $descriptor['uncompressed_bytes'], 'compression' => 'gzip',
                    'compressed_sha256' => $descriptor['compressed_sha256'], 'compressed_bytes' => $descriptor['compressed_bytes'],
                    'storage_disk' => $storageDisk, 'storage_path' => $path, 'received_at' => $now,
                ]);
                $locked->update([
                    'received_chunks' => (int) $locked->received_chunks + 1,
                    'received_uncompressed_bytes' => (int) $locked->received_uncompressed_bytes + (int) $descriptor['uncompressed_bytes'],
                    'received_compressed_bytes' => (int) $locked->received_compressed_bytes + (int) $descriptor['compressed_bytes'],
                    'expires_at' => $now->copy()->addHours((int) config('devboard.artifacts.incomplete_upload_ttl_hours', 24)),
                ]);

                return ['status_code' => 201, 'payload' => ['index' => $index, 'status' => 'accepted']];
            });
            if ($stale) {
                throw new GraphV2ImportException('graph_import_stale', 'Graph import is stale.', 410);
            }
            $committed = true;

            return $result;
        } finally {
            if ($path !== null && ! $committed) {
                $this->storage->deleteGraphChunk($import->id, $index, $storageDisk);
            }
            if (is_resource($validated['compressed'])) {
                fclose($validated['compressed']);
            }
        }
    }

    /** @return array{status_code:int,payload:array<string,mixed>} */
    public function complete(HadesGraphImport $import, string $artifactGraphVersion): array
    {
        $stale = false;
        $result = DB::transaction(function () use (&$stale, $artifactGraphVersion, $import): array {
            $locked = HadesGraphImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === HadesGraphImport::STATUS_STAGING && $this->expired($locked)) {
                $this->markStale($locked);
                $stale = true;

                return ['status_code' => 0, 'payload' => []];
            }
            $storedManifest = $this->storedManifest($locked);
            $this->assertStoredManifestIdentity($locked, $storedManifest);
            if ($artifactGraphVersion !== $locked->artifact_graph_version || $artifactGraphVersion !== ($storedManifest->artifact_graph_version ?? null)) {
                throw new GraphV2ImportException('graph_manifest_invalid', 'Artifact graph version does not match the manifest.');
            }
            if ($locked->status === HadesGraphImport::STATUS_STAGING) {
                if ($this->missingIndexes($locked) !== []
                    || (int) $locked->received_chunks !== (int) $locked->expected_chunks
                    || (int) $locked->received_uncompressed_bytes !== (int) $locked->expected_uncompressed_bytes
                    || (int) $locked->received_compressed_bytes !== (int) $locked->expected_compressed_bytes) {
                    throw new GraphV2ImportException('graph_import_incomplete', 'Graph import is missing one or more chunks.');
                }
                $locked->update(['status' => HadesGraphImport::STATUS_VALIDATING, 'completed_at' => now(), 'expires_at' => null]);

                return $this->completePayload($locked, 202);
            }
            if ($locked->status === HadesGraphImport::STATUS_VALIDATING) {
                return $this->completePayload($locked, 202);
            }
            if ($locked->status === HadesGraphImport::STATUS_FAILED) {
                throw new GraphV2ImportException('graph_import_failed', 'Graph import validation failed.', 409);
            }
            if ($locked->status === HadesGraphImport::STATUS_STALE) {
                throw new GraphV2ImportException('graph_import_stale', 'Graph import is stale.', 410);
            }
            $status = $this->publicationStatus($locked);

            return $this->completePayload($locked, $status === 'ready' ? 200 : 202);
        });
        if ($stale) {
            throw new GraphV2ImportException('graph_import_stale', 'Graph import is stale.', 410);
        }

        return $result;
    }

    /** @return array{status_code:int,payload:array<string,mixed>} */
    public function show(HadesGraphImport $import): array
    {
        return DB::transaction(function () use ($import): array {
            $locked = HadesGraphImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === HadesGraphImport::STATUS_STAGING && $this->expired($locked)) {
                $locked->status = HadesGraphImport::STATUS_STALE;
            }

            return $this->result($locked, 200, true);
        });
    }

    private function descriptor(HadesGraphImport $import, int $index): array
    {
        $descriptor = collect($import->manifest['chunks'] ?? [])->firstWhere('index', $index);
        if (! is_array($descriptor)) {
            throw new GraphV2ImportException('graph_chunk_invalid', 'Chunk index is not present in the manifest.');
        }

        return $descriptor;
    }

    private function ensureStaging(HadesGraphImport $import): void
    {
        if ($import->status === HadesGraphImport::STATUS_STALE) {
            throw new GraphV2ImportException('graph_import_stale', 'Graph import is stale.', 410);
        }
        if ($import->status !== HadesGraphImport::STATUS_STAGING) {
            throw new GraphV2ImportException('graph_import_not_staging', 'Graph import is not accepting chunks.');
        }
    }

    private function assertAdjacentOrder(HadesGraphImport $import, int $index, array $descriptor, string $first, string $last): void
    {
        $chunks = $import->manifest['chunks'] ?? [];
        foreach ([-1, 1] as $offset) {
            $neighborDescriptor = $chunks[$index + $offset] ?? null;
            if (! is_array($neighborDescriptor) || $neighborDescriptor['kind'] !== $descriptor['kind']) {
                continue;
            }
            $neighbor = HadesGraphImportChunk::query()->where('graph_import_id', $import->id)->where('chunk_index', $index + $offset)->lockForUpdate()->first();
            if (! $neighbor) {
                continue;
            }
            $boundary = $this->storedBoundary($import, $neighbor, $neighborDescriptor);
            if ($offset < 0 && strcmp($boundary['last_id'], $first) >= 0) {
                throw new GraphV2ImportException('graph_chunk_invalid', 'Graph chunk record IDs must be strictly increasing across adjacent chunks.');
            }
            if ($offset > 0 && strcmp($last, $boundary['first_id']) >= 0) {
                throw new GraphV2ImportException('graph_chunk_invalid', 'Graph chunk record IDs must be strictly increasing across adjacent chunks.');
            }
        }
    }

    /** @return array{first_id:string,last_id:string} */
    private function storedBoundary(HadesGraphImport $import, HadesGraphImportChunk $row, array $descriptor): array
    {
        $stream = $this->storage->readGraphChunkStream((string) $row->storage_disk, (string) $row->storage_path);
        $validated = null;
        try {
            $validated = $this->chunks->validate($import, (int) $row->chunk_index, $stream, [
                'Content-Type' => 'application/vnd.hades.graph-chunk+gzip',
                'X-Hades-Chunk-Sha256' => $row->sha256,
                'X-Hades-Chunk-Uncompressed-Bytes' => (string) $row->uncompressed_bytes,
                'X-Hades-Chunk-Compressed-Sha256' => $row->compressed_sha256,
                'X-Hades-Chunk-Compressed-Bytes' => (string) $row->compressed_bytes,
            ], $descriptor);

            return ['first_id' => $validated['first_id'], 'last_id' => $validated['last_id']];
        } finally {
            if (is_array($validated) && is_resource($validated['compressed'])) {
                fclose($validated['compressed']);
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function storedManifest(HadesGraphImport $import): \stdClass
    {
        $raw = DB::table('hades_graph_imports')->where('id', $import->id)->value('manifest');
        if (! is_string($raw) || $raw === '') {
            throw new GraphV2ImportException('graph_manifest_invalid', 'Stored graph manifest is malformed.');
        }
        try {
            $manifest = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'Stored graph manifest is malformed.');
        }
        if (! $manifest instanceof \stdClass) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'Stored graph manifest is not an object.');
        }

        return $manifest;
    }

    private function assertStoredManifestIdentity(HadesGraphImport $import, \stdClass $manifest): void
    {
        unset($manifest->generated_at);
        $digest = app(GraphV2Canonicalizer::class)->sha256($manifest);
        if (! hash_equals((string) $import->manifest_semantic_sha256, $digest)) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'Stored graph manifest identity is inconsistent.');
        }
    }

    private function expired(HadesGraphImport $import): bool
    {
        return $import->expires_at !== null && Carbon::parse($import->expires_at)->isPast();
    }

    private function markStale(HadesGraphImport $import): void
    {
        $import->update(['status' => HadesGraphImport::STATUS_STALE]);
    }

    /** @return array{status_code:int,payload:array<string,mixed>} */
    private function result(HadesGraphImport $import, int $status, bool $show = false): array
    {
        $payload = $show ? [
            'import_id' => $import->id, 'validation_status' => $import->status, 'publication_status' => $this->publicationStatus($import),
            'received_chunks' => (int) $import->received_chunks, 'expected_chunks' => (int) $import->expected_chunks,
            'missing_chunk_indexes' => $this->missingIndexes($import),
            'failure' => $import->failure_code === null ? null : ['code' => $import->failure_code, 'details' => $import->failure_details ?? []],
            'projection_version' => $this->projectionVersion($import), 'expires_at' => $this->iso($import->expires_at),
        ] : [
            'import_id' => $import->id, 'attempt_generation' => (int) $import->attempt_generation, 'validation_status' => $import->status,
            'publication_status' => $this->publicationStatus($import), 'missing_chunk_indexes' => $this->missingIndexes($import),
            'expires_at' => $this->iso($import->expires_at),
        ];

        return ['status_code' => $status, 'payload' => $payload];
    }

    private function completePayload(HadesGraphImport $import, int $status): array
    {
        return ['status_code' => $status, 'payload' => [
            'import_id' => $import->id, 'validation_status' => $import->status,
            'publication_status' => $this->publicationStatus($import), 'projection_version' => $this->projectionVersion($import),
        ]];
    }

    private function missingIndexes(HadesGraphImport $import): array
    {
        $received = $import->chunks()->pluck('chunk_index')->map(fn ($index): int => (int) $index)->all();
        $expected = is_array($import->manifest) ? array_column($import->manifest['chunks'] ?? [], 'index') : [];

        return array_values(array_diff($expected, $received));
    }

    private function projectionVersion(HadesGraphImport $import): ?string
    {
        $head = $this->projectionHead($import);

        return $head?->desired_projection_version;
    }

    private function publicationStatus(HadesGraphImport $import): string
    {
        $head = $this->projectionHead($import);
        if (! $head || $head->desired_projection_version === null) {
            return 'not_requested';
        }
        if ($head->failed_generation !== null
            && (int) $head->failed_generation === (int) $head->desired_generation
            && $head->failed_projection_version === $head->desired_projection_version) {
            return 'failed';
        }

        $projection = DB::table('canonical_graph_projections')
            ->where('project_id', $import->project_id)
            ->where('source_scope_type', 'workspace_binding')
            ->where('source_scope_id', $import->workspace_binding_id)
            ->where('artifact_graph_version', $import->artifact_graph_version)
            ->where('projection_version', $head->desired_projection_version)
            ->first();
        if (! $projection) {
            return 'queued';
        }

        return in_array($projection->status, ['queued', 'projecting', 'ready', 'failed', 'stale'], true)
            ? $projection->status
            : 'queued';
    }

    private function projectionHead(HadesGraphImport $import): ?object
    {
        return DB::table('canonical_graph_projection_heads')
            ->where('project_id', $import->project_id)
            ->where('source_scope_type', 'workspace_binding')
            ->where('source_scope_id', $import->workspace_binding_id)
            ->where('desired_artifact_graph_version', $import->artifact_graph_version)
            ->first();
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toISOString();
    }
}
