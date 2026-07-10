<?php

use App\Services\Graph\GraphQueryService;
use App\Services\Neo4j\FakeNeo4jClient;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);

    $client = new FakeNeo4jClient;
    $this->app->instance(FakeNeo4jClient::class, $client);
    $this->app->bind(GraphQueryService::class, fn () => new GraphQueryService($client));
});

function graphQueryProjectId(): string
{
    return DB::table('projects')->where('slug', 'demo-project')->value('id');
}

function graphQueryEnsureSnapshot(string $projectId, string $repoId, string $createdByUserId): string
{
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $createdByUserId,
        'name' => 'gq-test-device',
        'fingerprint_hash' => 'fake-gq-fingerprint',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.1.0',
        'status' => 'active',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repoId,
        'device_id' => $deviceId,
        'local_root_hash' => 'fake-root-hash',
        'display_path' => '/tmp/fake-repo',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repoId,
        'local_workspace_id' => $workspaceId,
        'device_id' => $deviceId,
        'started_by_user_id' => $createdByUserId,
        'runtime_profile' => 'default',
        'status' => 'completed',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'risk_level' => 'low',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $artifactId = (string) Str::ulid();
    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repoId,
        'run_id' => $runId,
        'artifact_type' => 'graph_snapshot',
        'storage_path' => 'fake-graph.json',
        'sha256' => 'test-hash',
        'size_bytes' => 100,
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'producer' => 'devboard-plugin',
        'status' => 'uploaded',
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('snapshots')->insert([
        'id' => $snapshotId,
        'project_id' => $projectId,
        'repository_id' => $repoId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'dirty_status' => 'clean',
        'graph_snapshot_artifact_id' => $artifactId,
        'created_by_run_id' => $runId,
        'created_at' => now(),
    ]);

    return $snapshotId;
}

function graphQueryUserId(): string
{
    return DB::table('users')->where('email', 'admin@example.com')->value('id');
}

function graphQueryCreateToken(string $scopes = 'projects.read'): array
{
    $user = DB::table('users')->where('email', 'admin@example.com')->first();
    $id = (string) Str::ulid();
    $secret = 'test-gq-secret';
    $prefix = 'devb_live_'.$id;
    $now = now();

    DB::table('api_tokens')->insert([
        'id' => $id,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $user->id,
        'device_id' => null,
        'name' => 'Graph Query Token',
        'scopes' => json_encode(explode(',', $scopes), JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => $id,
        'plain_token' => $prefix.'|'.$secret,
    ];
}

function graphQueryHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
    ];
}

function graphQueryBody(array $overrides = []): array
{
    return array_merge(['protocol_version' => 'v1'], $overrides);
}

function graphQueryFakeClient(): FakeNeo4jClient
{
    return app(FakeNeo4jClient::class);
}

it('returns 200 with structured query results for callers', function () {
    $token = graphQueryCreateToken('projects.read');
    $projectId = graphQueryProjectId();
    $repo = DB::table('repositories')->where('project_id', $projectId)->first();
    $userId = graphQueryUserId();
    graphQueryEnsureSnapshot($projectId, $repo->id, $userId);

    $response = $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callers', 'symbol_id' => 'App\\Services\\InvoiceService', 'limit' => 10]),
        graphQueryHeaders($token['plain_token']),
    )->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('query_type', 'callers');

    expect($response->json('found'))->toBeTrue();
});

it('returns 200 with structured query results for callees', function () {
    $token = graphQueryCreateToken('projects.read');
    $projectId = graphQueryProjectId();
    $repo = DB::table('repositories')->where('project_id', $projectId)->first();
    $userId = graphQueryUserId();
    graphQueryEnsureSnapshot($projectId, $repo->id, $userId);

    $response = $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callees', 'symbol_id' => 'App\\Services\\InvoiceService', 'limit' => 10]),
        graphQueryHeaders($token['plain_token']),
    )->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('query_type', 'callees');

    expect($response->json('found'))->toBeTrue();
});

it('returns 200 with structured query results for path queries', function () {
    $token = graphQueryCreateToken('projects.read');
    $projectId = graphQueryProjectId();
    $repo = DB::table('repositories')->where('project_id', $projectId)->first();
    $userId = graphQueryUserId();
    graphQueryEnsureSnapshot($projectId, $repo->id, $userId);

    $response = $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'path', 'from_symbol_id' => 'App\\Controllers\\Foo', 'to_symbol_id' => 'App\\Services\\Bar', 'max_depth' => 5]),
        graphQueryHeaders($token['plain_token']),
    )->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('query_type', 'path');

    expect($response->json('found'))->toBeTrue();
});

it('returns 403 for missing projects.read scope', function () {
    $token = graphQueryCreateToken('runs.write');
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callers', 'symbol_id' => 'App\\Services\\InvoiceService', 'limit' => 10]),
        graphQueryHeaders($token['plain_token']),
    )->assertForbidden();
});

it('returns 422 for unsupported query type', function () {
    $token = graphQueryCreateToken('projects.read');
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'invalid', 'symbol_id' => 'App\\Services\\InvoiceService']),
        graphQueryHeaders($token['plain_token']),
    )->assertStatus(422);
});

it('returns 401 without a valid token', function () {
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callers', 'symbol_id' => 'test']),
    )->assertUnauthorized();
});

it('resolves caller edges from fake neo4j client', function () {
    $projectId = graphQueryProjectId();
    $repo = DB::table('repositories')->where('project_id', $projectId)->first();
    $userId = graphQueryUserId();
    $snapshotId = graphQueryEnsureSnapshot($projectId, $repo->id, $userId);

    $this->app->bind(GraphQueryService::class, function () {
        return new GraphQueryService(new FakeNeo4jClient);
    });

    $token = graphQueryCreateToken('projects.read');

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callers', 'symbol_id' => 'App\\Services\\InvoiceService', 'limit' => 10]),
        graphQueryHeaders($token['plain_token']),
    )->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('query_type', 'callers')
        ->assertJsonPath('found', true);
});

it('callers cypher uses external_id and snapshot_id', function () {
    $fakeClient = new FakeNeo4jClient;
    $service = new GraphQueryService($fakeClient);

    $projectId = graphQueryProjectId();
    $repo = DB::table('repositories')->where('project_id', $projectId)->first();
    $userId = graphQueryUserId();
    graphQueryEnsureSnapshot($projectId, $repo->id, $userId);

    $service->query('callers', [
        'project_id' => $projectId,
        'symbol_id' => 'TestSymbol',
        'limit' => 5,
    ]);

    expect($fakeClient->commands)->toHaveCount(1);
    $cmd = $fakeClient->commands[0];

    expect($cmd['cypher'])->toContain('external_id');
    expect($cmd['cypher'])->toContain('snapshot_id');
    expect($cmd['cypher'])->not->toContain('{symbol_id:');

    expect($cmd['params'])->toHaveKey('snapshot_id');
    expect($cmd['params']['external_id'])->toBe('TestSymbol');
    expect($cmd['params'])->not->toHaveKey('symbol_id');
});

it('callees cypher uses external_id and snapshot_id', function () {
    $fakeClient = new FakeNeo4jClient;
    $service = new GraphQueryService($fakeClient);

    $projectId = graphQueryProjectId();
    $repo = DB::table('repositories')->where('project_id', $projectId)->first();
    $userId = graphQueryUserId();
    graphQueryEnsureSnapshot($projectId, $repo->id, $userId);

    $service->query('callees', [
        'project_id' => $projectId,
        'symbol_id' => 'TestSymbol',
        'limit' => 5,
    ]);

    expect($fakeClient->commands)->toHaveCount(1);
    $cmd = $fakeClient->commands[0];

    expect($cmd['cypher'])->toContain('external_id');
    expect($cmd['cypher'])->toContain('snapshot_id');
    expect($cmd['cypher'])->not->toContain('{symbol_id:');

    expect($cmd['params'])->toHaveKey('snapshot_id');
    expect($cmd['params']['external_id'])->toBe('TestSymbol');
    expect($cmd['params'])->not->toHaveKey('symbol_id');
});

it('path cypher uses external_id and snapshot_id', function () {
    $fakeClient = new FakeNeo4jClient;
    $service = new GraphQueryService($fakeClient);

    $projectId = graphQueryProjectId();
    $repo = DB::table('repositories')->where('project_id', $projectId)->first();
    $userId = graphQueryUserId();
    graphQueryEnsureSnapshot($projectId, $repo->id, $userId);

    $service->query('path', [
        'project_id' => $projectId,
        'from_symbol_id' => 'FromSymbol',
        'to_symbol_id' => 'ToSymbol',
        'max_depth' => 5,
    ]);

    expect($fakeClient->commands)->toHaveCount(1);
    $cmd = $fakeClient->commands[0];

    expect($cmd['cypher'])->toContain('external_id');
    expect($cmd['cypher'])->toContain('snapshot_id');
    expect($cmd['cypher'])->toContain(':CALLS*1..5');
    expect($cmd['cypher'])->not->toContain('{symbol_id:');
    expect($cmd['cypher'])->not->toContain('{from_symbol_id:');

    expect($cmd['params'])->toHaveKey('snapshot_id');
    expect($cmd['params']['from_external_id'])->toBe('FromSymbol');
    expect($cmd['params']['to_external_id'])->toBe('ToSymbol');
    expect($cmd['params'])->not->toHaveKey('from_symbol_id');
    expect($cmd['params'])->not->toHaveKey('to_symbol_id');
});

it('returns graph_snapshot_not_found when project has no snapshot', function () {
    $fakeClient = new FakeNeo4jClient;
    $service = new GraphQueryService($fakeClient);

    $projectId = graphQueryProjectId();
    DB::table('snapshots')->where('project_id', $projectId)->delete();

    $result = $service->query('callers', [
        'project_id' => $projectId,
        'symbol_id' => 'SomeSymbol',
    ]);

    expect($result['found'])->toBeFalse();
    expect($result['reason'])->toBe('graph_snapshot_not_found');
    expect($result['results'])->toBe([]);
    expect($fakeClient->commands)->toHaveCount(0);
});

it('queries are scoped to the project snapshot', function () {
    $fakeClient = new FakeNeo4jClient;
    $service = new GraphQueryService($fakeClient);

    $projectId = graphQueryProjectId();
    $repo = DB::table('repositories')->where('project_id', $projectId)->first();
    $userId = graphQueryUserId();
    $snapshotId = graphQueryEnsureSnapshot($projectId, $repo->id, $userId);

    $service->query('callers', [
        'project_id' => $projectId,
        'symbol_id' => 'SomeSymbol',
    ]);

    expect($fakeClient->commands)->toHaveCount(1);
    expect($fakeClient->commands[0]['params']['snapshot_id'])->toBe($snapshotId);
});
