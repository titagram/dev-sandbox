<?php

use App\Services\GenesisFinalizeService;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DevBoardSeeder::class);
});

it('lets a plugin create a verified wiki revision with evidence', function () {
    $context = createWikiRevisionContext();

    $response = $this->postJson("/api/plugin/v1/runs/{$context['run_id']}/wiki/revisions", wikiPayload($context, [
        'source_status' => 'verified_from_code',
        'evidence_refs' => [['type' => 'artifact', 'artifact_id' => 'art_123']],
    ]), wikiHeaders($context));

    $response
        ->assertOk()
        ->assertJsonPath('source_status', 'verified_from_code')
        ->assertJsonStructure(['wiki_page_id', 'wiki_revision_id']);

    expect(DB::table('wiki_revisions')->where('id', $response->json('wiki_revision_id'))->exists())->toBeTrue();
});

it('rejects verified_from_code revisions without evidence', function () {
    $context = createWikiRevisionContext();

    $this->postJson("/api/plugin/v1/runs/{$context['run_id']}/wiki/revisions", wikiPayload($context, [
        'source_status' => 'verified_from_code',
        'evidence_refs' => [],
    ]), wikiHeaders($context))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'schema_validation_failed');
});

it('accepts needs_verification revisions without evidence', function () {
    $context = createWikiRevisionContext();

    $this->postJson("/api/plugin/v1/runs/{$context['run_id']}/wiki/revisions", wikiPayload($context, [
        'source_status' => 'needs_verification',
        'evidence_refs' => [],
    ]), wikiHeaders($context))
        ->assertOk()
        ->assertJsonPath('source_status', 'needs_verification');
});

it('rejects wiki project and repository references that do not match the run', function () {
    $context = createWikiRevisionContext();
    $otherProjectId = (string) Str::ulid();
    $otherRepositoryId = (string) Str::ulid();
    $now = now();
    DB::table('projects')->insert([
        'id' => $otherProjectId,
        'name' => 'Other Wiki Project',
        'slug' => 'other-wiki-project',
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => DB::table('users')->where('email', 'admin@example.com')->value('id'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('repositories')->insert([
        'id' => $otherRepositoryId,
        'project_id' => $context['project_id'],
        'name' => 'Other Wiki Repository',
        'slug' => 'other-wiki-repository',
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => '[]',
        'excluded_paths' => '[]',
        'stack_hints' => '[]',
        'graph_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ([
        ['project_id' => $otherProjectId],
        ['repository_id' => $otherRepositoryId],
    ] as $overrides) {
        $this->postJson(
            "/api/plugin/v1/runs/{$context['run_id']}/wiki/revisions",
            wikiPayload($context, $overrides),
            wikiHeaders($context),
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'schema_validation_failed');
    }
});

it('keeps older revisions and updates current_revision_id', function () {
    $context = createWikiRevisionContext();

    $first = $this->postJson("/api/plugin/v1/runs/{$context['run_id']}/wiki/revisions", wikiPayload($context, [
        'title' => 'Routes',
        'content_markdown' => '# Routes v1',
        'source_status' => 'needs_verification',
        'evidence_refs' => [],
    ]), wikiHeaders($context))->json();

    $second = $this->postJson("/api/plugin/v1/runs/{$context['run_id']}/wiki/revisions", wikiPayload($context, [
        'title' => 'Routes',
        'content_markdown' => '# Routes v2',
        'source_status' => 'needs_verification',
        'evidence_refs' => [],
    ]), wikiHeaders($context))->json();

    expect(DB::table('wiki_revisions')->where('wiki_page_id', $first['wiki_page_id'])->count())->toBe(2);
    expect(DB::table('wiki_pages')->where('id', $first['wiki_page_id'])->value('current_revision_id'))->toBe($second['wiki_revision_id']);
    expect(DB::table('wiki_revisions')->where('id', $first['wiki_revision_id'])->exists())->toBeTrue();
});

it('writes wiki-pages artifact into revisions during Genesis finalize', function () {
    $context = createWikiGenesisContext();

    app(GenesisFinalizeService::class)->finalize($context['import_id']);

    $page = DB::table('wiki_pages')->where('slug', 'technical/routes')->first();

    expect($page)->not->toBeNull();
    expect($page->current_revision_id)->not->toBeNull();
    expect(DB::table('wiki_revisions')->where('id', $page->current_revision_id)->value('source_status'))->toBe('verified_from_code');
});

/**
 * @return array<string, string>
 */
function createWikiRevisionContext(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $now = now();
    $deviceId = (string) Str::ulid();
    $tokenId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $secret = 'wiki-secret';
    $prefix = 'devb_live_'.$tokenId;

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Wiki Device',
        'fingerprint_hash' => 'sha256:wiki-device',
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
        'name' => 'Wiki Token',
        'scopes' => json_encode(['wiki.write'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => null,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $userId,
        'runtime_profile' => 'agent_plugin',
        'status' => 'started',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'summary' => null,
        'risk_level' => 'low',
        'started_at' => $now,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'token' => $prefix.'|'.$secret,
        'device_id' => $deviceId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wikiPayload(array $context, array $overrides = []): array
{
    return array_merge([
        'protocol_version' => 'v1',
        'project_id' => $context['project_id'],
        'repository_id' => $context['repository_id'],
        'slug' => 'technical/routes',
        'title' => 'Routes',
        'page_type' => 'technical',
        'producer' => 'plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
        'content_markdown' => '# Routes',
        'evidence_refs' => [['type' => 'artifact', 'artifact_id' => 'art_123']],
    ], $overrides);
}

/**
 * @return array<string, string>
 */
function wikiHeaders(array $context): array
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
function createWikiGenesisContext(): array
{
    $context = createWikiRevisionContext();
    $workspaceId = (string) Str::ulid();
    $importId = (string) Str::ulid();
    $wikiArtifactId = (string) Str::ulid();
    $securityArtifactId = (string) Str::ulid();
    $now = now();

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $context['repository_id'],
        'device_id' => $context['device_id'],
        'local_root_hash' => 'sha256:wiki-genesis-workspace',
        'display_path' => '/tmp/wiki-genesis-workspace',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('genesis_imports')->insert([
        'id' => $importId,
        'project_id' => $context['project_id'],
        'repository_id' => $context['repository_id'],
        'local_workspace_id' => $workspaceId,
        'run_id' => $context['run_id'],
        'status' => 'uploading',
        'manifest_artifact_id' => null,
        'snapshot_id' => null,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'started_at' => $now,
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    addFinalizableArtifact($context, $importId, $wikiArtifactId, 'wiki_pages', json_encode([
        'pages' => [
            wikiPayload($context, [
                'slug' => 'technical/routes',
                'title' => 'Routes',
                'source_status' => 'verified_from_code',
                'evidence_refs' => [['type' => 'artifact', 'artifact_id' => $wikiArtifactId]],
            ]),
        ],
    ], JSON_THROW_ON_ERROR));
    addFinalizableArtifact($context, $importId, $securityArtifactId, 'security_report', '{"blocked":[]}');

    return array_merge($context, [
        'import_id' => $importId,
        'local_workspace_id' => $workspaceId,
    ]);
}

function addFinalizableArtifact(array $context, string $importId, string $artifactId, string $type, string $content): void
{
    $path = "devboard/artifacts/genesis/{$importId}/{$artifactId}/artifact";
    $chunkPath = "devboard/artifacts/genesis/{$importId}/{$artifactId}/chunks/0";
    Storage::disk('local')->put($chunkPath, $content);

    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $context['project_id'],
        'repository_id' => $context['repository_id'],
        'run_id' => $context['run_id'],
        'artifact_type' => $type,
        'storage_path' => $path,
        'sha256' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'uploading',
        'producer' => 'devboard-python-plugin',
        'metadata' => json_encode(['chunk_count' => 1], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
