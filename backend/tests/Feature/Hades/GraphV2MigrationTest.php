<?php

use App\Models\CanonicalGraphProjectionHead;
use App\Models\HadesGraphImport;
use App\Models\HadesGraphImportChunk;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates graph v2 storage without columns outside the approved schema', function (): void {
    $expectedColumns = [
        'hades_graph_imports' => [
            'id', 'project_id', 'workspace_binding_id', 'hades_agent_id', 'attempt_generation',
            'schema', 'artifact_graph_version', 'manifest_semantic_sha256', 'source_identity',
            'manifest', 'status', 'completeness_status', 'expected_chunks', 'received_chunks',
            'expected_uncompressed_bytes', 'received_uncompressed_bytes',
            'expected_compressed_bytes', 'received_compressed_bytes', 'failure_code',
            'failure_details', 'completed_at', 'validated_at', 'validation_started_at',
            'validation_heartbeat_at', 'validation_attempts', 'validation_run_token_hash',
            'validation_lease_expires_at', 'expires_at', 'created_at', 'updated_at',
        ],
        'hades_graph_import_chunks' => [
            'id', 'graph_import_id', 'chunk_index', 'kind', 'sha256', 'record_count',
            'uncompressed_bytes', 'compression', 'compressed_sha256', 'compressed_bytes',
            'storage_disk', 'storage_path', 'received_at', 'created_at', 'updated_at',
        ],
        'hades_graph_import_record_keys' => [
            'graph_import_id', 'record_kind', 'public_id', 'chunk_index', 'record_ordinal',
        ],
        'hades_graph_import_file_paths' => [
            'graph_import_id', 'path', 'file_node_public_id', 'file_sha256',
        ],
        'hades_graph_import_references' => [
            'id', 'graph_import_id', 'owner_record_kind', 'owner_public_id',
            'reference_kind', 'target_record_kind', 'target_public_id',
        ],
        'canonical_graph_projection_heads' => [
            'id', 'project_id', 'source_scope_type', 'source_scope_id', 'desired_generation',
            'desired_artifact_graph_version', 'desired_verification_set_hash',
            'desired_projection_version', 'active_projection_id', 'previous_projection_id',
            'failed_generation', 'failed_projection_version', 'failed_at', 'created_at',
            'updated_at',
        ],
    ];

    foreach ($expectedColumns as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue();

        if (! Schema::hasTable($table)) {
            continue;
        }

        $actualColumns = Schema::getColumnListing($table);

        expect(array_diff($columns, $actualColumns))->toBe([])
            ->and(array_diff($actualColumns, $columns))->toBe([]);
    }

    expect(Schema::hasColumns('hades_graph_imports', [
        'project_id', 'workspace_binding_id', 'attempt_generation', 'schema',
        'artifact_graph_version', 'manifest_semantic_sha256', 'status',
        'validation_attempts', 'validation_run_token_hash', 'validation_lease_expires_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('canonical_graph_projections', [
        'graph_import_id', 'graph_contract_version', 'artifact_graph_version',
        'verification_set_hash', 'projection_version', 'source_identity', 'completeness',
        'base_node_count', 'base_relationship_count', 'base_flow_count',
        'effective_node_count', 'effective_relationship_count', 'effective_flow_count',
    ]))->toBeTrue();

    expect(Schema::hasColumns('canonical_graph_projection_attempts', [
        'desired_generation', 'candidate_projection_version',
    ]))->toBeTrue();
});

it('allows only one live import for a project binding and artifact version', function (): void {
    if (! Schema::hasTable('hades_graph_imports')) {
        expect(Schema::hasTable('hades_graph_imports'))->toBeTrue();

        return;
    }

    $context = graphV2MigrationContext();
    $artifactVersion = str_repeat('a', 64);

    DB::table('hades_graph_imports')->insert([
        graphV2ImportPayload($context, $artifactVersion, 1, 'staging'),
    ]);

    expect(fn () => DB::table('hades_graph_imports')->insert([
        graphV2ImportPayload($context, $artifactVersion, 2, 'validating'),
    ]))->toThrow(QueryException::class);

    DB::table('hades_graph_imports')->where('attempt_generation', 1)->update([
        'status' => 'failed',
    ]);

    DB::table('hades_graph_imports')->insert([
        graphV2ImportPayload($context, $artifactVersion, 2, 'staging'),
    ]);
});

it('rejects cross-import references through composite record-key foreign keys', function (): void {
    if (! Schema::hasTable('hades_graph_import_references')) {
        expect(Schema::hasTable('hades_graph_import_references'))->toBeTrue();

        return;
    }

    $context = graphV2MigrationContext();
    $firstImport = (string) Str::ulid();
    $secondImport = (string) Str::ulid();

    DB::table('hades_graph_imports')->insert([
        graphV2ImportPayload($context, str_repeat('b', 64), 1, 'validating', $firstImport),
        graphV2ImportPayload($context, str_repeat('c', 64), 1, 'validating', $secondImport),
    ]);
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $firstImport,
        'record_kind' => 'nodes',
        'public_id' => 'owner-node',
        'chunk_index' => 0,
        'record_ordinal' => 0,
    ]);
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $secondImport,
        'record_kind' => 'nodes',
        'public_id' => 'target-node',
        'chunk_index' => 0,
        'record_ordinal' => 0,
    ]);

    expect(fn () => DB::table('hades_graph_import_references')->insert([
        'graph_import_id' => $firstImport,
        'owner_record_kind' => 'nodes',
        'owner_public_id' => 'owner-node',
        'reference_kind' => 'target',
        'target_record_kind' => 'nodes',
        'target_public_id' => 'target-node',
    ]))->toThrow(QueryException::class);
});

it('permits the nodes and entrypoints public id pair but rejects every other cross-kind collision', function (): void {
    if (! Schema::hasTable('hades_graph_import_record_keys')) {
        expect(Schema::hasTable('hades_graph_import_record_keys'))->toBeTrue();

        return;
    }

    $context = graphV2MigrationContext();
    $importId = (string) Str::ulid();

    DB::table('hades_graph_imports')->insert(
        graphV2ImportPayload($context, str_repeat('d', 64), 1, 'validating', $importId),
    );
    DB::table('hades_graph_import_record_keys')->insert([
        [
            'graph_import_id' => $importId,
            'record_kind' => 'nodes',
            'public_id' => 'same-public-id',
            'chunk_index' => 0,
            'record_ordinal' => 0,
        ],
        [
            'graph_import_id' => $importId,
            'record_kind' => 'entrypoints',
            'public_id' => 'same-public-id',
            'chunk_index' => 0,
            'record_ordinal' => 1,
        ],
    ]);

    expect(fn () => DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $importId,
        'record_kind' => 'structures',
        'public_id' => 'same-public-id',
        'chunk_index' => 0,
        'record_ordinal' => 2,
    ]))->toThrow(QueryException::class);
});

it('enforces record kind and cross-kind guards on SQLite updates', function (): void {
    $context = graphV2MigrationContext();
    $importId = (string) Str::ulid();

    DB::table('hades_graph_imports')->insert(
        graphV2ImportPayload($context, str_repeat('7', 64), 1, 'validating', $importId),
    );
    DB::table('hades_graph_import_record_keys')->insert([
        [
            'graph_import_id' => $importId,
            'record_kind' => 'nodes',
            'public_id' => 'collision-id',
            'chunk_index' => 0,
            'record_ordinal' => 0,
        ],
        [
            'graph_import_id' => $importId,
            'record_kind' => 'structures',
            'public_id' => 'structure-id',
            'chunk_index' => 0,
            'record_ordinal' => 1,
        ],
    ]);

    expect(fn () => DB::table('hades_graph_import_record_keys')
        ->where('public_id', 'structure-id')
        ->update(['record_kind' => 'not-a-kind']))->toThrow(QueryException::class);
    expect(fn () => DB::table('hades_graph_import_record_keys')
        ->where('public_id', 'structure-id')
        ->update(['public_id' => 'collision-id']))->toThrow(QueryException::class);
});

it('rejects validated import updates but allows deletion with child cascade', function (): void {
    if (! Schema::hasTable('hades_graph_imports')) {
        expect(Schema::hasTable('hades_graph_imports'))->toBeTrue();

        return;
    }

    $context = graphV2MigrationContext();
    $importId = (string) Str::ulid();
    DB::table('hades_graph_imports')->insert(
        graphV2ImportPayload($context, str_repeat('e', 64), 1, 'staging', $importId),
    );
    $chunkId = (string) Str::ulid();
    DB::table('hades_graph_import_chunks')->insert(
        graphV2ChunkPayload($importId, $chunkId),
    );
    DB::table('hades_graph_imports')->where('id', $importId)->update([
        'status' => 'validated',
    ]);

    expect(fn () => DB::table('hades_graph_imports')->where('id', $importId)->update([
        'failure_code' => 'must-not-change',
    ]))->toThrow(QueryException::class);

    expect(DB::table('hades_graph_imports')->where('id', $importId)->delete())->toBe(1)
        ->and(DB::table('hades_graph_imports')->where('id', $importId)->exists())->toBeFalse()
        ->and(DB::table('hades_graph_import_chunks')->where('id', $chunkId)->exists())->toBeFalse();
});

it('makes chunks immutable while their parent import is validated', function (): void {
    $context = graphV2MigrationContext();
    $importId = (string) Str::ulid();
    DB::table('hades_graph_imports')->insert(
        graphV2ImportPayload($context, str_repeat('1', 64), 1, 'staging', $importId),
    );
    $chunkId = (string) Str::ulid();
    DB::table('hades_graph_import_chunks')->insert(
        graphV2ChunkPayload($importId, $chunkId),
    );
    DB::table('hades_graph_imports')->where('id', $importId)->update([
        'status' => 'validated',
    ]);

    expect(fn () => DB::table('hades_graph_import_chunks')->insert(
        graphV2ChunkPayload($importId, (string) Str::ulid(), 1),
    ))->toThrow(QueryException::class);
    expect(fn () => DB::table('hades_graph_import_chunks')->where('id', $chunkId)->update([
        'storage_path' => 'must-not-change',
    ]))->toThrow(QueryException::class);
    expect(fn () => DB::table('hades_graph_import_chunks')->where('id', $chunkId)->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('hades_graph_imports')->where('id', $importId)->delete())->toBe(1)
        ->and(DB::table('hades_graph_import_chunks')->where('id', $chunkId)->exists())->toBeFalse();
});

it('enforces import generation and validated expiry invariants at the database boundary', function (): void {
    $context = graphV2MigrationContext();

    expect(fn () => DB::table('hades_graph_imports')->insert(
        graphV2ImportPayload($context, str_repeat('2', 64), -1, 'staging'),
    ))->toThrow(QueryException::class);

    expect(fn () => DB::table('hades_graph_imports')->insert(
        graphV2ImportPayload(
            $context,
            str_repeat('3', 64),
            1,
            'validated',
            null,
            ['expires_at' => now()],
        ),
    ))->toThrow(QueryException::class);
});

it('rejects negative values in every graph v2 unsigned integer family', function (): void {
    $context = graphV2MigrationContext();

    foreach ([
        'attempt_generation',
        'expected_chunks',
        'received_chunks',
        'expected_uncompressed_bytes',
        'received_uncompressed_bytes',
        'expected_compressed_bytes',
        'received_compressed_bytes',
        'validation_attempts',
    ] as $offset => $column) {
        expect(fn () => DB::table('hades_graph_imports')->insert(
            graphV2ImportPayload(
                $context,
                str_repeat(dechex($offset + 4), 64),
                1,
                'staging',
                null,
                [$column => -1],
            ),
        ))->toThrow(QueryException::class);
    }

    foreach (['chunk_index', 'record_count', 'uncompressed_bytes', 'compressed_bytes'] as $offset => $column) {
        $importId = (string) Str::ulid();
        DB::table('hades_graph_imports')->insert(
            graphV2ImportPayload($context, str_repeat(dechex($offset + 20), 64), 1, 'staging', $importId),
        );

        expect(fn () => DB::table('hades_graph_import_chunks')->insert(
            graphV2ChunkPayload($importId, (string) Str::ulid(), 0, [$column => -1]),
        ))->toThrow(QueryException::class);
    }
});

it('enforces one projection head per project and workspace binding scope', function (): void {
    if (! Schema::hasTable('canonical_graph_projection_heads')) {
        expect(Schema::hasTable('canonical_graph_projection_heads'))->toBeTrue();

        return;
    }

    $context = graphV2MigrationContext();
    $head = [
        'id' => (string) Str::ulid(),
        'project_id' => $context['project_id'],
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => $context['workspace_binding_id'],
        'desired_generation' => 0,
        'desired_artifact_graph_version' => null,
        'desired_verification_set_hash' => null,
        'desired_projection_version' => null,
        'active_projection_id' => null,
        'previous_projection_id' => null,
        'failed_generation' => null,
        'failed_projection_version' => null,
        'failed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('canonical_graph_projection_heads')->insert($head);

    expect(fn () => DB::table('canonical_graph_projection_heads')->insert([
        ...$head,
        'id' => (string) Str::ulid(),
    ]))->toThrow(QueryException::class);
});

it('casts graph v2 models and exposes their approved relationships', function (): void {
    $context = graphV2MigrationContext();
    $importId = (string) Str::ulid();
    $now = Carbon::now()->toImmutable();

    HadesGraphImport::query()->create(graphV2ImportPayload(
        $context,
        str_repeat('f', 64),
        1,
        'validating',
        $importId,
        [
            'source_identity' => ['workspace_binding_id' => $context['workspace_binding_id']],
            'manifest' => ['chunks' => 1],
            'failure_details' => ['reason' => 'none'],
            'completed_at' => $now,
        ],
    ));

    $import = HadesGraphImport::query()->findOrFail($importId);

    expect($import->source_identity)->toBe(['workspace_binding_id' => $context['workspace_binding_id']])
        ->and($import->manifest)->toBe(['chunks' => 1])
        ->and($import->failure_details)->toBe(['reason' => 'none'])
        ->and($import->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($import->project->id)->toBe($context['project_id'])
        ->and($import->workspaceBinding->id)->toBe($context['workspace_binding_id'])
        ->and($import->hadesAgent->getRawOriginal('id'))->toBe($context['hades_agent_id'])
        ->and($import->getCasts()['source_identity'])->toBe('array')
        ->and($import->getCasts()['completed_at'])->toBe('immutable_datetime');

    $chunk = HadesGraphImportChunk::query()->create([
        'id' => (string) Str::ulid(),
        'graph_import_id' => $importId,
        'chunk_index' => 0,
        'kind' => 'nodes',
        'sha256' => str_repeat('1', 64),
        'record_count' => 1,
        'uncompressed_bytes' => 10,
        'compression' => 'gzip',
        'compressed_sha256' => str_repeat('2', 64),
        'compressed_bytes' => 20,
        'storage_disk' => 'local',
        'storage_path' => 'imports/'.$importId.'/0.gz',
        'received_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect($chunk->graphImport->id)->toBe($importId)
        ->and($chunk->received_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($chunk->getCasts()['received_at'])->toBe('immutable_datetime')
        ->and($import->fresh()->chunks->modelKeys())->toContain($chunk->id);

    $head = CanonicalGraphProjectionHead::query()->create([
        'id' => (string) Str::ulid(),
        'project_id' => $context['project_id'],
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => $context['workspace_binding_id'],
        'desired_generation' => 4,
        'desired_artifact_graph_version' => str_repeat('3', 64),
        'desired_verification_set_hash' => str_repeat('4', 64),
        'desired_projection_version' => str_repeat('5', 64),
        'active_projection_id' => null,
        'previous_projection_id' => null,
        'failed_generation' => 3,
        'failed_projection_version' => str_repeat('6', 64),
        'failed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect($head->desired_generation)->toBe(4)
        ->and($head->failed_generation)->toBe(3)
        ->and($head->failed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($head->project->id)->toBe($context['project_id'])
        ->and($head->getCasts()['desired_generation'])->toBe('integer')
        ->and($head->getCasts()['failed_at'])->toBe('immutable_datetime');
});

it('generates ULIDs when graph v2 models are created without ids', function (): void {
    $context = graphV2MigrationContext();
    $now = Carbon::now()->toImmutable();
    $importPayload = graphV2ImportPayload($context, str_repeat('8', 64), 1, 'staging');
    unset($importPayload['id']);
    $importPayload['source_identity'] = ['project_id' => $context['project_id']];
    $importPayload['manifest'] = ['chunks' => []];

    $import = HadesGraphImport::query()->create($importPayload);
    $chunkPayload = graphV2ChunkPayload($import->getKey(), null, 0);
    $chunkPayload['received_at'] = $now;
    $chunk = HadesGraphImportChunk::query()->create($chunkPayload);
    $head = CanonicalGraphProjectionHead::query()->create([
        'project_id' => $context['project_id'],
        'source_scope_type' => CanonicalGraphProjectionHead::SCOPE_WORKSPACE_BINDING,
        'source_scope_id' => $context['workspace_binding_id'],
        'desired_generation' => 0,
    ]);

    expect($import->getKey())->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i')
        ->and($chunk->getKey())->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i')
        ->and($head->getKey())->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/i')
        ->and(HadesGraphImport::LIVE_STATUSES)->toBe([
            HadesGraphImport::STATUS_STAGING,
            HadesGraphImport::STATUS_VALIDATING,
        ])
        ->and(HadesGraphImportChunk::COMPRESSION_GZIP)->toBe('gzip')
        ->and(HadesGraphImportChunk::KINDS)->toHaveCount(7)
        ->and($now)->toBeInstanceOf(CarbonImmutable::class);
});

/**
 * @return array{project_id: string, hades_agent_id: string, workspace_binding_id: string}
 */
function graphV2MigrationContext(): array
{
    $now = now();
    $userId = DB::table('users')->insertGetId([
        'name' => 'Graph v2 test user',
        'email' => 'graph-v2-'.Str::lower(Str::random(12)).'@example.test',
        'password' => 'password',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Graph v2 test project',
        'slug' => 'graph-v2-'.Str::lower(Str::random(12)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $agentId = (string) Str::ulid();
    DB::table('hades_agents')->insert([
        'id' => $agentId,
        'project_id' => $projectId,
        'external_agent_id' => 'graph-v2-agent-'.Str::lower(Str::random(8)),
        'label' => 'Graph v2 test agent',
        'platform' => 'test',
        'version' => '1.0.0',
        'declared_capabilities' => json_encode([], JSON_THROW_ON_ERROR),
        'effective_capabilities' => json_encode([], JSON_THROW_ON_ERROR),
        'last_seen_at' => null,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $bindingId = (string) Str::ulid();
    DB::table('hades_workspace_bindings')->insert([
        'id' => $bindingId,
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'external_agent_id' => 'graph-v2-agent-binding',
        'local_project_id' => null,
        'workspace_fingerprint' => hash('sha256', $bindingId),
        'display_path' => '/tmp/graph-v2-test',
        'git_remote_display' => null,
        'git_remote_hash' => null,
        'head_commit' => null,
        'platform' => 'test',
        'status' => 'linked',
        'linked_at' => $now,
        'unlinked_at' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'workspace_binding_id' => $bindingId,
    ];
}

/**
 * @param  array{project_id: string, hades_agent_id: string, workspace_binding_id: string}  $context
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function graphV2ImportPayload(
    array $context,
    string $artifactVersion,
    int $attemptGeneration,
    string $status,
    ?string $id = null,
    array $overrides = [],
): array {
    $now = now();

    return array_merge([
        'id' => $id ?? (string) Str::ulid(),
        'project_id' => $context['project_id'],
        'workspace_binding_id' => $context['workspace_binding_id'],
        'hades_agent_id' => $context['hades_agent_id'],
        'attempt_generation' => $attemptGeneration,
        'schema' => 'hades.code_graph.v2',
        'artifact_graph_version' => $artifactVersion,
        'manifest_semantic_sha256' => str_repeat('0', 64),
        'source_identity' => json_encode(['project_id' => $context['project_id']], JSON_THROW_ON_ERROR),
        'manifest' => json_encode(['chunks' => []], JSON_THROW_ON_ERROR),
        'status' => $status,
        'completeness_status' => 'complete',
        'expected_chunks' => 1,
        'received_chunks' => 0,
        'expected_uncompressed_bytes' => 0,
        'received_uncompressed_bytes' => 0,
        'expected_compressed_bytes' => 0,
        'received_compressed_bytes' => 0,
        'failure_code' => null,
        'failure_details' => null,
        'completed_at' => null,
        'validated_at' => null,
        'validation_started_at' => null,
        'validation_heartbeat_at' => null,
        'validation_attempts' => 0,
        'validation_run_token_hash' => null,
        'validation_lease_expires_at' => null,
        'expires_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function graphV2ChunkPayload(
    string $importId,
    ?string $id,
    int $chunkIndex = 0,
    array $overrides = [],
): array {
    $now = now();

    return array_merge([
        'id' => $id,
        'graph_import_id' => $importId,
        'chunk_index' => $chunkIndex,
        'kind' => 'nodes',
        'sha256' => str_repeat('1', 64),
        'record_count' => 1,
        'uncompressed_bytes' => 10,
        'compression' => 'gzip',
        'compressed_sha256' => str_repeat('2', 64),
        'compressed_bytes' => 20,
        'storage_disk' => 'local',
        'storage_path' => 'imports/'.$importId.'/'.$chunkIndex.'.gz',
        'received_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);
}
