<?php

use App\Jobs\ImportGraphToNeo4j;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['services.devboard.graph_import_mode' => 'fake']);
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('records local snapshot metadata before delta sync', function () {
    $context = createDeltaContext();

    deltaLocalSnapshot($context, [
        'local_workspace_id' => $context['local_workspace_id'],
        'branch' => 'feature/devboard',
        'head_sha' => 'def456',
        'dirty_status' => 'dirty',
        'changed_files' => [['path' => 'app.py', 'change_type' => 'modified']],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'local_snapshot_received');

    expect(DB::table('local_workspaces')->where('id', $context['local_workspace_id'])->value('last_head_sha'))->toBe('def456');
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'local_snapshot.received')->exists())->toBeTrue();
});

it('starts delta sync and creates artifact rows', function () {
    $context = createDeltaContext();
    $manifest = deltaManifest([
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ]);

    $response = deltaStart($context, $manifest);

    $response
        ->assertOk()
        ->assertJsonPath('status', 'uploading')
        ->assertJsonStructure(['delta_id', 'artifacts']);

    expect(DB::table('delta_syncs')->where('id', $response->json('delta_id'))->value('status'))->toBe('uploading');
    expect(DB::table('delta_syncs')->where('id', $response->json('delta_id'))->value('base_snapshot_id'))->toBe($context['base_snapshot_id']);
    expect(DB::table('artifacts')->where('run_id', $context['run_id'])->count())->toBe(2);
});

it('finalizes a valid delta bundle and creates a new snapshot', function () {
    Queue::fake();

    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('delta_manifest', 'delta-manifest.json', '{"protocol_version":"v1"}'),
        deltaArtifact('file_hashes', 'file-hashes.json', '{"hashes":[]}'),
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
        deltaArtifact('wiki_pages', 'wiki-pages.json', '{"pages":[]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId)
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['snapshot_id']);

    Queue::assertPushed(ImportGraphToNeo4j::class, function (ImportGraphToNeo4j $job) use ($deltaId): bool {
        return $job->scope === 'delta' && $job->importOrDeltaId === $deltaId;
    });

    $job = new ImportGraphToNeo4j('delta', $deltaId);
    $job->handle(app(\App\Services\GenesisGraphImportService::class));

    $newSnapshotId = DB::table('delta_syncs')->where('id', $deltaId)->value('new_snapshot_id');

    expect($newSnapshotId)->not->toBeNull();
    expect($newSnapshotId)->not->toBe($context['base_snapshot_id']);
    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('active');
    expect(DB::table('local_workspaces')->where('id', $context['local_workspace_id'])->value('last_snapshot_id'))->toBe($newSnapshotId);
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'delta.finalized')->exists())->toBeTrue();
    expect(DB::table('run_events')->where('run_id', $context['run_id'])->where('event_type', 'graph.imported')->exists())->toBeTrue();
});

it('finalizes a large multi-chunk Delta artifact after retrying a chunk upload', function () {
    $context = createDeltaContext();
    $largeContent = json_encode([
        'hashes' => array_map(
            fn (int $index): array => [
                'path' => "src/ChangedFile{$index}.php",
                'sha256' => hash('sha256', str_repeat("changed {$index}\n", 50)),
            ],
            range(1, 180),
        ),
    ], JSON_THROW_ON_ERROR);
    $chunks = str_split($largeContent, 1024);
    $fileHashes = deltaArtifact('file_hashes', 'file-hashes.json', $largeContent, count($chunks));
    $artifacts = [
        deltaArtifact('delta_manifest', 'delta-manifest.json', '{"protocol_version":"v1"}'),
        $fileHashes,
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":180}'),
        deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        if ($artifact['artifact_id'] !== $fileHashes['artifact_id']) {
            deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
        }
    }

    foreach ($chunks as $index => $chunk) {
        deltaChunk($context, $deltaId, $fileHashes['artifact_id'], $index, $chunk, hash('sha256', $chunk))->assertOk();

        if ($index === 2) {
            deltaChunk($context, $deltaId, $fileHashes['artifact_id'], $index, $chunk, hash('sha256', $chunk))->assertOk();
        }
    }

    deltaFinalize($context, $deltaId)
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $storagePath = DB::table('artifacts')->where('id', $fileHashes['artifact_id'])->value('storage_path');

    expect(Storage::disk('local')->get($storagePath))->toBe($largeContent);
    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('active');
});

it('blocks delta finalize when security report contains blocked findings', function () {
    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[{"path":".env"}]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'secret_scan_blocked');

    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('failed');
});

it('allows delta finalize with blocked findings only when explicitly approved', function () {
    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[{"path":".env","reason":"env_file"}]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId, ['allow_blocked_security_findings' => true])
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['snapshot_id']);

    expect(DB::table('delta_syncs')->where('id', $deltaId)->value('status'))->toBe('active');
    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'security.blocked_upload_approved')
        ->exists())->toBeTrue();
    expect(DB::table('audit_logs')
        ->where('action', 'security.blocked_upload_approved')
        ->where('target_type', 'delta_sync')
        ->where('target_id', $deltaId)
        ->exists())->toBeTrue();
});

it('dispatches a graph import job instead of importing synchronously when finalizing a delta with a graph snapshot', function () {
    Queue::fake();

    $context = createDeltaContext();
    $artifacts = [
        deltaArtifact('delta_manifest', 'delta-manifest.json', '{"protocol_version":"v1"}'),
        deltaArtifact('file_hashes', 'file-hashes.json', '{"hashes":[]}'),
        deltaArtifact('diff_summary', 'diff-summary.json', '{"changed_file_count":1}'),
        deltaArtifact('graph_snapshot', 'graph-snapshot.json', '{"nodes":[],"relationships":[]}'),
        deltaArtifact('security_report', 'security-report.json', '{"blocked":[]}'),
        deltaArtifact('wiki_pages', 'wiki-pages.json', '{"pages":[]}'),
    ];
    $deltaId = deltaStart($context, deltaManifest($artifacts))->json('delta_id');

    foreach ($artifacts as $artifact) {
        deltaChunk($context, $deltaId, $artifact['artifact_id'], 0, $artifact['content'], hash('sha256', $artifact['content']))->assertOk();
    }

    deltaFinalize($context, $deltaId)
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonStructure(['snapshot_id']);

    Queue::assertPushed(ImportGraphToNeo4j::class, function (ImportGraphToNeo4j $job) use ($deltaId): bool {
        return $job->scope === 'delta' && $job->importOrDeltaId === $deltaId;
    });

    expect(DB::table('run_events')
        ->where('run_id', $context['run_id'])
        ->where('event_type', 'graph.imported')
        ->exists())->toBeFalse();
});

/**
 * @return array<string, string>
 */
function createDeltaContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $now = now();
    $deviceId = (string) Str::ulid();
    $tokenId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $baseSnapshotId = (string) Str::ulid();
    $secret = 'delta-upload-secret';
    $prefix = 'devb_live_'.$tokenId;

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Delta Device',
        'fingerprint_hash' => 'sha256:delta-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('api_tokens')->insert([
        'id' => $tokenId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Delta Token',
        'scopes' => json_encode(['repositories.read', 'runs.write', 'artifacts.write'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:delta-workspace',
        'display_path' => '/tmp/delta-workspace',
        'current_branch' => 'feature/devboard',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => $baseSnapshotId,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $userId,
        'runtime_profile' => 'agent_plugin',
        'status' => 'started',
        'branch' => 'feature/devboard',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => null,
        'risk_level' => 'medium',
        'started_at' => $now,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $baseSnapshotId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => null,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    return [
        'token' => $prefix.'|'.$secret,
        'device_id' => $deviceId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'base_snapshot_id' => $baseSnapshotId,
    ];
}

/**
 * @param list<array<string, mixed>> $artifacts
 * @return array<string, mixed>
 */
function deltaManifest(array $artifacts): array
{
    return [
        'protocol_version' => 'v1',
        'bundle_type' => 'delta_sync',
        'schema_version' => 'v1',
        'changed_file_count' => 1,
        'risk_report' => ['risk_level' => 'medium', 'triggers' => ['large_multi_file_diff']],
        'artifacts' => array_map(fn (array $artifact): array => Arr::except($artifact, ['content']), $artifacts),
    ];
}

/**
 * @return array<string, mixed>
 */
function deltaArtifact(string $type, string $filename, string $content, int $chunkCount = 1): array
{
    return [
        'protocol_version' => 'v1',
        'artifact_id' => (string) Str::ulid(),
        'artifact_type' => $type,
        'schema_version' => 'v1',
        'filename' => $filename,
        'mime_type' => 'application/json',
        'sha256' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'chunk_count' => $chunkCount,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
        'content' => $content,
    ];
}

function deltaLocalSnapshot(array $context, array $payload): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/plugin/v1/runs/{$context['run_id']}/local-snapshots", array_merge([
        'protocol_version' => 'v1',
    ], $payload), deltaHeaders($context));
}

function deltaStart(array $context, array $manifest): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/plugin/v1/runs/{$context['run_id']}/delta-syncs", [
        'protocol_version' => 'v1',
        'local_workspace_id' => $context['local_workspace_id'],
        'base_snapshot_id' => $context['base_snapshot_id'],
        'branch' => 'feature/devboard',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'dirty',
        'manifest' => $manifest,
    ], deltaHeaders($context));
}

function deltaChunk(
    array $context,
    ?string $deltaId,
    string $artifactId,
    int $index,
    string $content,
    string $hash,
): Illuminate\Testing\TestResponse {
    return test()->call(
        'PUT',
        "/api/plugin/v1/delta-syncs/{$deltaId}/artifacts/{$artifactId}/chunks/{$index}",
        [],
        [],
        [],
        array_merge(deltaRawHeaders($context), [
            'HTTP_X_DEVBOARD_CHUNK_SHA256' => $hash,
            'HTTP_X_DEVBOARD_CHUNK_SIZE' => strlen($content),
            'CONTENT_TYPE' => 'application/octet-stream',
        ]),
        $content,
    );
}

function deltaFinalize(array $context, ?string $deltaId, array $payload = []): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/plugin/v1/delta-syncs/{$deltaId}/finalize", array_merge([
        'protocol_version' => 'v1',
    ], $payload), deltaHeaders($context));
}

/**
 * @return array<string, string>
 */
function deltaHeaders(array $context): array
{
    return [
        'Authorization' => 'Bearer '.$context['token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
        'X-DevBoard-Device-Id' => $context['device_id'],
    ];
}

/**
 * @return array<string, string>
 */
function deltaRawHeaders(array $context): array
{
    return [
        'HTTP_AUTHORIZATION' => 'Bearer '.$context['token'],
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_DEVBOARD_PROTOCOL' => 'v1',
        'HTTP_X_DEVBOARD_PLUGIN_VERSION' => '0.1.0',
        'HTTP_X_DEVBOARD_DEVICE_ID' => $context['device_id'],
    ];
}
