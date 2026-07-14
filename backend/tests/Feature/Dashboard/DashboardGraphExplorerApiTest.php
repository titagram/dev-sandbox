<?php

use App\Models\User;
use App\Http\Controllers\Dashboard\Api\DashboardGraphExplorerController;
use App\Services\Graph\CanonicalGraphQueryService;
use App\Services\Graph\DashboardGraphExplorerService;
use App\Services\Graph\DashboardGraphPublicHandle;
use App\Services\Neo4j\Neo4jClient;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.key' => str_repeat('a', 32)]);
    $this->seed(DevBoardSeeder::class);
});

it('exposes the dashboard graph query endpoint to an authenticated reader', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    $roleId = DB::table('roles')->where('name', 'Admin')->value('id');
    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'scopes',
        ])
        ->assertOk();
});

it('allows the dashboard reader roles and rejects unauthenticated requests', function (string $role): void {
    $user = task3ApiUser($role);
    $projectId = task3ApiProject();

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'scopes'])
        ->assertOk()
        ->assertJsonPath('query_type', 'scopes')
        ->assertJsonMissingPath('plugin_token');

    $this->getJson('/api/dashboard/me')->assertOk();
})->with(['Admin', 'PM', 'Developer', 'Sysadmin']);

it('uses the exact shared public kind vocabulary at the endpoint boundary', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $scope = task3ApiRepositoryScope($projectId);
    $fixture = task3ApiBindExplorer($this, $projectId, $scope['id'], $scope['graph_version']);
    $fake = $fixture['client'];

    foreach (['method', 'class', 'method_reference', 'external_class', 'table', 'route', 'trait', 'external_symbol', 'interface', 'file'] as $kind) {
        $fake->nodeKind = $kind;
        $this->actingAs($user)
            ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
                'type' => 'detail',
                'scope_type' => 'repository',
                'scope_id' => $scope['id'],
                'node_handle' => $fixture['source_handle'],
            ])
            ->assertOk()
            ->assertJsonPath('node.kind', $kind);
    }

    foreach (['service', 'module', 'function', 'model', 'enum', 'http_endpoint', '/srv/private/Foo.php'] as $kind) {
        $fake->nodeKind = $kind;
        $this->actingAs($user)
            ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
                'type' => 'detail',
                'scope_type' => 'repository',
                'scope_id' => $scope['id'],
                'node_handle' => $fixture['source_handle'],
            ])
            ->assertOk()
            ->assertJsonPath('node.kind', 'unknown');
    }
});

it('rejects Agent and no-role users from the shared dashboard reader contract', function (): void {
    $projectId = task3ApiProject();
    $agent = task3ApiUser('Agent');
    $noRole = User::factory()->create(['status' => 'active']);

    $this->actingAs($agent)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'scopes'])
        ->assertForbidden();

    $this->actingAs($noRole)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'scopes'])
        ->assertForbidden();
});

it('requires dashboard authentication', function (): void {
    $projectId = task3ApiProject();

    $this->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'scopes'])
        ->assertUnauthorized();
});

it('defaults a non-scopes query when the project has exactly one selectable scope', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $scope = task3ApiRepositoryScope($projectId);
    task3ApiBindExplorer($this, $projectId, $scope['id'], $scope['graph_version']);

    $response = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'overview',
        ])
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('scope.type', 'repository')
        ->assertJsonPath('scope.id', $scope['id'])
        ->assertJsonPath('projection.active_graph_version', $scope['graph_version']);

    expect($response->json('projection.generated_at'))
        ->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/');
});

it('returns scopes and requires an explicit scope when multiple project scopes are selectable', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $repository = task3ApiRepositoryScope($projectId);
    task3ApiWorkspaceScope($projectId, 'workspace-v1');
    task3ApiBindExplorer($this, $projectId, $repository['id'], $repository['graph_version']);

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'scopes'])
        ->assertOk()
        ->assertJsonPath('returned', 2)
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.source_scope_type', 'repository')
        ->assertJsonPath('items.0.source_scope_id', $repository['id'])
        ->assertJsonPath('items.0.active_graph_version', $repository['graph_version'])
        ->assertJsonPath('items.0.status', 'ready')
        ->assertJsonPath('items.0.quality', 'full')
        ->assertJsonPath('items.0.node_count', 3)
        ->assertJsonPath('items.0.relationship_count', 1)
        ->assertJsonMissingPath('items.0.artifact_id')
        ->assertJsonMissingPath('items.0.graph_version');

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'overview'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'scope_required')
        ->assertJsonPath('items.0.source_scope_type', 'repository')
        ->assertJsonPath('items.0.source_scope_id', $repository['id'])
        ->assertJsonPath('items.0.active_graph_version', $repository['graph_version'])
        ->assertJsonPath('items.0.status', 'ready')
        ->assertJsonMissingPath('items.0.artifact_id')
        ->assertJsonMissingPath('items.0.graph_version');
});

it('filters deleted repositories and unlinked workspace bindings before scope selection', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $repository = task3ApiRepositoryScope($projectId, 'stale-repository-v1');
    $remaining = task3ApiWorkspaceScope($projectId, 'remaining-workspace-v1');
    $unlinked = task3ApiWorkspaceScope($projectId, 'unlinked-workspace-v1');
    DB::table('hades_workspace_bindings')->where('id', $unlinked['id'])->update(['status' => 'unlinked']);
    DB::table('repositories')->where('id', $repository['id'])->delete();
    task3ApiBindExplorer($this, $projectId, $remaining['id'], $remaining['graph_version']);

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'scopes'])
        ->assertOk()
        ->assertJsonPath('returned', 1)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.source_scope_type', 'workspace_binding')
        ->assertJsonPath('items.0.source_scope_id', $remaining['id'])
        ->assertJsonMissing(['source_scope_id' => $repository['id']])
        ->assertJsonMissing(['source_scope_id' => $unlinked['id']]);

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'overview'])
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('scope.type', 'workspace_binding')
        ->assertJsonPath('scope.id', $remaining['id']);
});

it('does not require a scope when no selectable scope exists', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $repository = task3ApiRepositoryScope($projectId, 'deleted-only-v1');
    DB::table('repositories')->where('id', $repository['id'])->delete();

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'overview'])
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonPath('reason', 'graph_projection_not_ready')
        ->assertJsonPath('scope', null)
        ->assertJsonPath('returned', 0)
        ->assertJsonPath('limit', 100)
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('next_cursor', null);
});

it('preserves paginated scope metadata when omitted scope selection is ambiguous', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    task3ApiManyRepositoryScopes($projectId, 101);

    $response = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'overview'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'scope_required');

    expect($response->json('returned'))->toBe(100)
        ->and($response->json('limit'))->toBe(100)
        ->and($response->json('has_more'))->toBeTrue()
        ->and($response->json('next_cursor'))->toBeString()
        ->and($response->json('items'))->toHaveCount(100);
});

it('returns safe validation envelopes for partial scope input', function (array $scope): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'overview',
            ...$scope,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'validation_failed')
        ->assertJsonPath('scope', null);
})->with([
    [['scope_type' => 'repository']],
    [['scope_id' => 'repository-only']],
]);

it('denies a foreign scope without revealing whether it has a graph', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $foreignProjectId = task3ApiOtherProject();
    $foreignScope = task3ApiRepositoryScope($foreignProjectId);

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'overview',
            'scope_type' => 'repository',
            'scope_id' => $foreignScope['id'],
        ])
        ->assertNotFound()
        ->assertJsonPath('reason', 'scope_not_found')
        ->assertJsonMissing(['scope_id' => $foreignScope['id']]);
});

it('validates the bounded query contract and forbids internal or plugin fields', function (array $body): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();

    $response = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", $body)
        ->assertUnprocessable();

    expect($response->json())->not->toHaveKey('external_id')
        ->and($response->json())->not->toHaveKey('graph_version');
})->with([
    [['type' => 'search', 'query' => '', 'limit' => 1]],
    [['type' => 'search', 'query' => str_repeat('q', 161), 'limit' => 1]],
    [['type' => 'search', 'query' => 'q', 'limit' => 101]],
    [['type' => 'overview', 'limit' => 51]],
    [['type' => 'neighborhood', 'direction' => 'sideways']],
    [['type' => 'neighborhood', 'families' => ['unknown']]],
    [['type' => 'neighborhood', 'max_depth' => 4]],
    [['type' => 'impact', 'max_depth' => 1]],
    [['type' => 'detail', 'node_handle' => 'preview-node-id']],
    [['type' => 'overview', 'graph_version' => 'origin-v1']],
    [['type' => 'overview', 'external_id' => 'method:Secret']],
    [['type' => 'overview', 'symbol_id' => 'method:Secret']],
    [['type' => 'overview', 'plugin_token' => 'secret']],
    [['type' => 'overview', 'authorization' => 'Bearer secret']],
    [['type' => 'overview', 'cursor' => str_repeat('c', 513)]],
    [['type' => 'overview', 'limit' => 0]],
]);

it('rejects real plugin authentication and DevBoard headers on the dashboard endpoint', function (string $header): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();

    $this->actingAs($user)
        ->withHeader($header, 'secret')
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", ['type' => 'scopes'])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'validation_failed');
})->with([
    'Authorization',
    'X-DevBoard-Protocol',
    'X-DevBoard-Plugin-Version',
    'X-DevBoard-Device-Id',
    'X-DevBoard-Timestamp',
    'X-DevBoard-Content-SHA256',
    'X-DevBoard-Signature',
]);

it('accepts signed cursors only for scopes and rejects tampered cursors', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $repository = task3ApiRepositoryScope($projectId, 'cursor-repository-v1');
    task3ApiWorkspaceScope($projectId, 'cursor-workspace-v1');
    $fixture = task3ApiBindExplorer($this, $projectId, $repository['id'], $repository['graph_version']);

    $scopePage = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'scopes',
            'limit' => 1,
        ])
        ->assertOk();
    $scopeCursor = $scopePage->json('next_cursor');
    expect($scopeCursor)->toBeString()->not->toBe('');

    $tampered = substr_replace($scopeCursor, $scopeCursor[10] === 'A' ? 'B' : 'A', 10, 1);
    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'scopes',
            'cursor' => $tampered,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid_cursor');

    foreach ([
        ['type' => 'overview'],
        ['type' => 'detail', 'node_handle' => $fixture['source_handle']],
        ['type' => 'neighborhood', 'node_handle' => $fixture['source_handle']],
        ['type' => 'path', 'from_handle' => $fixture['source_handle'], 'to_handle' => $fixture['target_handle']],
        ['type' => 'impact', 'node_handle' => $fixture['target_handle']],
    ] as $body) {
        $this->actingAs($user)
            ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
                ...$body,
                'scope_type' => 'repository',
                'scope_id' => $repository['id'],
                'cursor' => $scopeCursor,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'validation_failed');
    }
});

it('returns a not-ready envelope for unavailable traversal, path, and impact projections', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $scope = task3ApiRepositoryScope($projectId, 'unavailable-v1');
    DB::table('canonical_graph_projections')
        ->where('project_id', $projectId)
        ->where('source_scope_type', 'repository')
        ->where('source_scope_id', $scope['id'])
        ->delete();
    $source = (new DashboardGraphPublicHandle)->forNode($projectId, 'repository', $scope['id'], $scope['graph_version'], 'method:source');
    $target = (new DashboardGraphPublicHandle)->forNode($projectId, 'repository', $scope['id'], $scope['graph_version'], 'method:target');

    foreach ([
        ['type' => 'neighborhood', 'node_handle' => $source],
        ['type' => 'path', 'from_handle' => $source, 'to_handle' => $target],
        ['type' => 'impact', 'node_handle' => $target],
    ] as $body) {
        $this->actingAs($user)
            ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
                ...$body,
                'scope_type' => 'repository',
                'scope_id' => $scope['id'],
            ])
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('reason', 'graph_projection_not_ready')
            ->assertJsonCount(0, 'items')
            ->assertJsonCount(0, 'edges');
    }
});

it('returns public semantic items, families, why explanations, and bounded envelopes', function (): void {
    $user = task3ApiUser('Developer');
    $projectId = task3ApiProject();
    $scope = task3ApiRepositoryScope($projectId);
    $fixture = task3ApiBindExplorer($this, $projectId, $scope['id'], $scope['graph_version']);
    $handle = $fixture['source_handle'];
    $target = $fixture['target_handle'];

    $search = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'search',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'query' => 'Invoice',
            'limit' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('returned', 1)
        ->assertJsonPath('limit', 1)
        ->assertJsonPath('items.0.kind', 'method')
        ->assertJsonPath('source.type', 'canonical_graph');

    $neighborhood = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'neighborhood',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'node_handle' => $handle,
            'direction' => 'out',
            'families' => ['call'],
            'max_depth' => 2,
            'limit' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('items.0.kind', 'method')
        ->assertJsonPath('edges.0.family', 'call');

    $path = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'path',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'from_handle' => $handle,
            'to_handle' => $target,
            'max_depth' => 2,
            'limit' => 50,
        ])
        ->assertOk()
        ->assertJsonPath('edges.0.edge_type', 'CALLS_METHOD');

    $impact = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'impact',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'node_handle' => $target,
            'max_depth' => 2,
            'limit' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('items.0.family', 'call')
        ->assertJsonPath('items.0.why', 'call edge CALLS_METHOD');

    foreach ([$search->json(), $neighborhood->json(), $path->json(), $impact->json()] as $payload) {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        expect($encoded)
            ->not->toContain('external_id')
            ->not->toContain('artifact_id')
            ->not->toContain('projection_id')
            ->not->toContain('plugin_token')
            ->not->toContain('Secret.php');
    }
});

it('preserves safe root nodes and normalizes public edge endpoint keys', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $scope = task3ApiRepositoryScope($projectId);
    $fixture = task3ApiBindExplorer($this, $projectId, $scope['id'], $scope['graph_version']);

    $detail = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'detail',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'node_handle' => $fixture['source_handle'],
        ])
        ->assertOk()
        ->assertJsonPath('node.handle', $fixture['source_handle'])
        ->assertJsonPath('node.kind', 'method');

    $neighborhood = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'neighborhood',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'node_handle' => $fixture['source_handle'],
            'direction' => 'out',
            'limit' => 10,
        ])
        ->assertOk()
        ->assertJsonPath('node.handle', $fixture['source_handle'])
        ->assertJsonPath('edges.0.from_handle', $fixture['source_handle'])
        ->assertJsonPath('edges.0.to_handle', $fixture['target_handle'])
        ->assertJsonMissingPath('edges.0.source_handle')
        ->assertJsonMissingPath('edges.0.target_handle');

    expect($detail->json('node'))->not->toHaveKey('external_id')
        ->and($neighborhood->json('node'))->not->toHaveKey('external_id');
});

it('returns a typed safe envelope when the selected projection is unavailable', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $scopeId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');

    $response = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'overview',
            'scope_type' => 'repository',
            'scope_id' => $scopeId,
        ])
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonPath('reason', 'graph_projection_not_ready');

    expect($response->json('limit'))->toBeInt()
        ->and($response->json('projection.node_count'))->toBeInt()
        ->and($response->json('projection.relationship_count'))->toBeInt()
        ->and($response->json('items'))->toBeArray()
        ->and($response->json('edges'))->toBeArray();
});

it('reports malformed handles as invalid_handle without exposing a canonical id', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $scope = task3ApiRepositoryScope($projectId);

    $response = $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'detail',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'node_handle' => 'preview-node-id',
        ])
        ->assertUnprocessable();

    expect($response->json('reason'))->toBe('invalid_handle')
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('external_id');
});

it('recursively removes internal, path, and plugin keys from every public response level', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $scope = task3ApiRepositoryScope($projectId);
    $itemHandle = (new DashboardGraphPublicHandle)->forNode($projectId, 'repository', $scope['id'], 'nested-v1', 'method:item');
    $sourceHandle = (new DashboardGraphPublicHandle)->forNode($projectId, 'repository', $scope['id'], 'nested-v1', 'method:source');
    $targetHandle = (new DashboardGraphPublicHandle)->forNode($projectId, 'repository', $scope['id'], 'nested-v1', 'method:target');
    $controller = new DashboardGraphExplorerController(new DashboardGraphExplorerService(
        new CanonicalGraphQueryService,
    ));
    $responseMethod = (new ReflectionClass($controller))->getMethod('response');
    $responseMethod->setAccessible(true);
    $response = $responseMethod->invoke($controller, $projectId, 'overview', [
        'type' => 'repository',
        'id' => $scope['id'],
    ], [
        'found' => true,
        'reason' => null,
        'projection' => [
            'status' => 'ready',
            'node_count' => 1,
            'nested' => [
                'external_id' => 'method:secret',
                'artifact_id' => 'artifact-secret',
                'projection_id' => 'projection-secret',
                'graph_version' => 'origin-secret',
                'path' => '/srv/private/Secret.php',
                'plugin_credentials' => ['token' => 'secret'],
            ],
        ],
        'items' => [[
            'handle' => $itemHandle,
            'nested' => [
                'symbol_id' => 'method:secret',
                'from_symbol_id' => 'method:secret',
                'to_symbol_id' => 'method:secret',
                'raw_path' => '../backend/app/Secret.php',
                'headers' => ['authorization' => 'Bearer secret'],
            ],
        ]],
        'edges' => [[
            'source_handle' => $sourceHandle,
            'target_handle' => $targetHandle,
            'nested' => [
                'external_id' => 'edge-secret',
                'plugin_token' => 'secret',
            ],
        ]],
        'returned' => 1,
        'limit' => 50,
    ]);
    $payload = $response->getData(true);
    expect(json_encode($payload, JSON_THROW_ON_ERROR))
        ->not->toContain('method:secret')
        ->not->toContain('artifact-secret')
        ->not->toContain('projection-secret')
        ->not->toContain('/srv/private/Secret.php')
        ->not->toContain('Bearer secret')
        ->not->toContain('plugin_token');
});

it('requires GH1 handles at the public response boundary', function (): void {
    $validHandle = (new DashboardGraphPublicHandle)->forNode(
        'project-1',
        'repository',
        'scope-1',
        'graph-v1',
        'method:Invoice',
    );
    $payload = task3ApiBoundaryResponse([
        'found' => true,
        'items' => [[
            'handle' => 'preview-node-id',
            'kind' => 'method',
            'label' => 'not public',
        ]],
        'node' => [
            'handle' => 'preview-node-id',
            'kind' => 'method',
            'label' => 'not public',
        ],
        'edges' => [[
            'source_handle' => $validHandle,
            'target_handle' => 'preview-node-id',
            'type' => 'CALLS_METHOD',
        ]],
    ]);

    expect($payload['items'])->toBe([])
        ->and($payload['node'])->toBeNull()
        ->and($payload['edges'])->toBe([]);
});

it('maps only the exact live edge vocabulary to closed semantic families', function (): void {
    $source = (new DashboardGraphPublicHandle)->forNode('project-1', 'repository', 'scope-1', 'graph-v1', 'method:source');
    $target = (new DashboardGraphPublicHandle)->forNode('project-1', 'repository', 'scope-1', 'graph-v1', 'method:target');
    $families = [
        'CALLS' => 'call',
        'CALLS_METHOD' => 'call',
        'STATIC_CALL' => 'call',
        'USES_DEPENDENCY' => 'dependency',
        'INSTANTIATES' => 'dependency',
        'EXTENDS' => 'dependency',
        'USES_FORM_REQUEST' => 'dependency',
        'THROWS_EXCEPTION' => 'dependency',
        'API_RESOURCE_REF' => 'dependency',
        'ROUTE_HANDLER' => 'route',
        'TEST_COVERS_SYMBOL' => 'test',
        'TEST_IMPORTS' => 'test',
        'TEST_COVERS_ROUTE' => 'test',
        'QUERY_TABLE' => 'table',
        'ELOQUENT_QUERY' => 'table',
    ];
    $payload = task3ApiBoundaryResponse([
        'found' => true,
        'edges' => array_map(
            fn (string $type): array => [
                'source_handle' => $source,
                'target_handle' => $target,
                'type' => $type,
            ],
            array_keys($families),
        ),
    ]);

    expect($payload['edges'])->toHaveCount(15);
    foreach ($payload['edges'] as $edge) {
        expect($edge['family'])->toBe($families[$edge['edge_type']])
            ->and($edge)->not->toHaveKey('source_handle')
            ->and($edge)->not->toHaveKey('target_handle');
    }

    $invalid = task3ApiBoundaryResponse([
        'found' => true,
        'edges' => [[
            'source_handle' => $source,
            'target_handle' => $target,
            'type' => 'RELATED',
            'family' => 'other',
        ]],
    ]);
    expect($invalid['edges'])->toBe([]);
});

it('keeps graph item fields typed, bounded, and within the coarse public DTO', function (): void {
    $handle = (new DashboardGraphPublicHandle)->forNode(
        'project-1',
        'repository',
        'scope-1',
        'graph-v1',
        'method:Invoice',
    );
    $payload = task3ApiBoundaryResponse([
        'found' => true,
        'items' => [[
            'handle' => $handle,
            'kind' => 'producer-secret-kind',
            'label' => str_repeat('l', 513),
            'score' => '0.5',
            'distance' => '1',
            'family' => ['call'],
            'edge_types' => ['CALLS_METHOD', 'producer-secret'],
            'why' => str_repeat('w', 513),
            'degree' => 7,
            'risk' => 'high',
            'repository' => 'internal',
        ]],
    ]);

    expect($payload['items'])->toHaveCount(1)
        ->and($payload['items'][0])->toMatchArray([
            'handle' => $handle,
            'kind' => 'unknown',
        ])
        ->and($payload['items'][0])->not->toHaveKey('label')
        ->and($payload['items'][0])->not->toHaveKey('score')
        ->and($payload['items'][0])->not->toHaveKey('distance')
        ->and($payload['items'][0])->not->toHaveKey('family')
        ->and($payload['items'][0])->not->toHaveKey('why')
        ->and($payload['items'][0]['edge_types'])->toBe(['CALLS_METHOD'])
        ->and($payload['items'][0])->not->toHaveKey('degree')
        ->and($payload['items'][0])->not->toHaveKey('risk')
        ->and($payload['items'][0])->not->toHaveKey('repository');
});

it('reports returned as the count of sanitized public items for nodes and scopes', function (): void {
    $handle = (new DashboardGraphPublicHandle)->forNode(
        'project-1',
        'repository',
        'scope-1',
        'graph-v1',
        'method:Invoice',
    );
    $nodePayload = task3ApiBoundaryResponse([
        'found' => true,
        'returned' => 99,
        'items' => [
            ['handle' => $handle, 'kind' => 'method', 'label' => 'Invoice'],
            ['handle' => 'preview-node-id', 'kind' => 'method', 'label' => 'hidden'],
        ],
        'limit' => 2,
        'has_more' => true,
        'truncated' => true,
    ]);
    $scopePayload = task3ApiBoundaryResponse([
        'found' => true,
        'returned' => 99,
        'items' => [
            [
                'source_scope_type' => 'repository',
                'source_scope_id' => 'scope-1',
                'active_graph_version' => 'graph-v1',
                'status' => 'ready',
                'quality' => 'full',
                'node_count' => 1,
                'relationship_count' => 0,
            ],
            [
                'source_scope_type' => 'internal',
                'source_scope_id' => ['not-a-scope'],
            ],
        ],
        'limit' => 2,
        'has_more' => true,
        'truncated' => true,
    ]);

    expect($nodePayload['returned'])->toBe(1)
        ->and($nodePayload['limit'])->toBe(2)
        ->and($nodePayload['has_more'])->toBeTrue()
        ->and($nodePayload['truncated'])->toBeTrue()
        ->and($scopePayload['returned'])->toBe(1)
        ->and($scopePayload['limit'])->toBe(2)
        ->and($scopePayload['has_more'])->toBeTrue()
        ->and($scopePayload['truncated'])->toBeTrue();
});

it('maps result reasons to a closed public vocabulary and safe status behavior', function (): void {
    $known = [
        'graph_projection_not_ready',
        'graph_projection_rebuild_required',
        'graph_scope_not_found',
        'node_not_found',
        'path_not_found',
        'scope_required',
        'scope_not_found',
        'invalid_handle',
        'invalid_cursor',
        'invalid_query',
        'invalid_family',
        'invalid_direction',
        'validation_failed',
        'query_error',
    ];
    foreach ($known as $reason) {
        expect(task3ApiBoundaryResponse(['found' => false, 'reason' => $reason])['reason'])->toBe($reason);
    }

    expect(task3ApiBoundaryResponse([
        'found' => false,
        'reason' => 'database password leaked',
    ])['reason'])->toBe('query_error');
});

it('drops non-string and nested edge type members without warnings', function (): void {
    $payload = task3ApiBoundaryResponse([
        'found' => true,
        'items' => [[
            'handle' => (new DashboardGraphPublicHandle)->forNode(
                'project-1',
                'repository',
                'scope-1',
                'graph-v1',
                'method:Invoice',
            ),
            'kind' => 'method',
            'edge_types' => ['CALLS_METHOD', ['CALLS'], (object) ['type' => 'EXTENDS'], 'producer-secret'],
        ]],
    ]);

    expect($payload['items'][0]['edge_types'])->toBe(['CALLS_METHOD']);
});

it('leaves the plugin graph route token-protected', function (): void {
    $projectId = task3ApiProject();

    $this->postJson("/api/plugin/v1/projects/{$projectId}/graph/query", [
        'type' => 'path',
        'from_symbol_id' => 'method:source',
        'to_symbol_id' => 'method:target',
    ])->assertUnauthorized();
});

it('distinguishes malformed handles from well-formed handles that do not resolve', function (): void {
    $user = task3ApiUser('Admin');
    $projectId = task3ApiProject();
    $scope = task3ApiRepositoryScope($projectId);
    $fixture = task3ApiBindExplorer($this, $projectId, $scope['id'], $scope['graph_version']);

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'detail',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'node_handle' => 'preview-node-id',
        ])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->postJson("/api/dashboard/projects/{$projectId}/graph/query", [
            'type' => 'detail',
            'scope_type' => 'repository',
            'scope_id' => $scope['id'],
            'node_handle' => (new DashboardGraphPublicHandle)->forNode(
                $projectId,
                'repository',
                $scope['id'],
                $scope['graph_version'],
                'method:missing',
            ),
        ])
        ->assertNotFound()
        ->assertJsonPath('reason', 'node_not_found')
        ->assertJsonMissingPath('external_id');

    expect($fixture['source_handle'])->toStartWith('gh1_');
});

function task3ApiUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function task3ApiProject(): string
{
    return (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
}

/** @return array{id:string,graph_version:string} */
function task3ApiRepositoryScope(string $projectId, string $version = 'task3-repository-v1'): array
{
    $scopeId = (string) DB::table('repositories')->where('project_id', $projectId)->value('id');
    DB::table('canonical_graph_projections')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'source_scope_type' => 'repository',
        'source_scope_id' => $scopeId,
        'artifact_type' => 'graph_snapshot',
        'artifact_id' => (string) Str::ulid(),
        'graph_version' => $version,
        'active_graph_version' => $version,
        'checksum' => hash('sha256', $version),
        'head_commit' => str_repeat('a', 40),
        'quality' => 'full',
        'status' => 'ready',
        'node_count' => 3,
        'relationship_count' => 1,
        'projected_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['id' => $scopeId, 'graph_version' => $version];
}

function task3ApiManyRepositoryScopes(string $projectId, int $count): void
{
    $now = now();
    for ($index = 0; $index < $count; $index++) {
        $scopeId = (string) Str::ulid();
        $version = 'many-repository-'.$index;
        DB::table('repositories')->insert([
            'id' => $scopeId,
            'project_id' => $projectId,
            'name' => 'many-repository-'.$index,
            'slug' => 'many-repository-'.$index,
            'protected_paths' => '[]',
            'excluded_paths' => '[]',
            'stack_hints' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('canonical_graph_projections')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'source_scope_type' => 'repository',
            'source_scope_id' => $scopeId,
            'artifact_type' => 'graph_snapshot',
            'artifact_id' => (string) Str::ulid(),
            'graph_version' => $version,
            'active_graph_version' => $version,
            'checksum' => hash('sha256', $version),
            'head_commit' => str_repeat('a', 40),
            'quality' => 'full',
            'status' => 'ready',
            'node_count' => 1,
            'relationship_count' => 0,
            'projected_at' => $now->copy()->addSeconds($index),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

/** @return array{id:string,graph_version:string} */
function task3ApiWorkspaceScope(string $projectId, string $version): array
{
    $agentId = (string) Str::ulid();
    $scopeId = (string) Str::ulid();
    DB::table('hades_agents')->insert([
        'id' => $agentId,
        'project_id' => $projectId,
        'external_agent_id' => 'task3-agent-'.$agentId,
        'label' => 'Task 3 agent',
        'platform' => 'linux',
        'version' => 'test',
        'declared_capabilities' => '[]',
        'effective_capabilities' => '[]',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hades_workspace_bindings')->insert([
        'id' => $scopeId,
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'external_agent_id' => 'task3-agent-'.$agentId,
        'workspace_fingerprint' => hash('sha256', $scopeId),
        'display_path' => '/srv/private/task3',
        'head_commit' => str_repeat('b', 40),
        'status' => 'linked',
        'linked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('canonical_graph_projections')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => $scopeId,
        'artifact_type' => 'hades_agent_artifact',
        'artifact_id' => (string) Str::ulid(),
        'graph_version' => $version,
        'active_graph_version' => $version,
        'checksum' => hash('sha256', $version),
        'head_commit' => str_repeat('b', 40),
        'quality' => 'full',
        'status' => 'ready',
        'node_count' => 3,
        'relationship_count' => 1,
        'projected_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['id' => $scopeId, 'graph_version' => $version];
}

function task3ApiOtherProject(): string
{
    $projectId = (string) Str::ulid();
    $userId = (string) DB::table('users')->where('email', 'admin@example.com')->value('id');
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Task 3 Foreign Project',
        'slug' => 'task-3-foreign-'.strtolower(substr($projectId, -8)),
        'status' => 'active',
        'created_by_user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('repositories')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'name' => 'foreign-repository',
        'slug' => 'foreign-repository',
        'protected_paths' => '[]',
        'excluded_paths' => '[]',
        'stack_hints' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $projectId;
}

/** @return array{source_handle:string,target_handle:string,client:Task3ApiNeo4jClient} */
function task3ApiBindExplorer($test, string $projectId, string $scopeId, string $version): array
{
    $sourceId = 'method:InvoiceService::charge';
    $targetId = 'method:InvoiceService::save';
    $handles = [
        'source_handle' => (new DashboardGraphPublicHandle)->forNode($projectId, 'repository', $scopeId, $version, $sourceId),
        'target_handle' => (new DashboardGraphPublicHandle)->forNode($projectId, 'repository', $scopeId, $version, $targetId),
    ];
    $client = new Task3ApiNeo4jClient($projectId, $scopeId, $version, $handles);
    app()->instance(
        DashboardGraphExplorerService::class,
        new DashboardGraphExplorerService(new CanonicalGraphQueryService($client), $client),
    );

    return [...$handles, 'client' => $client];
}

/** @param array<string,mixed> $result @return array<string,mixed> */
function task3ApiBoundaryResponse(array $result): array
{
    $controller = new DashboardGraphExplorerController(new DashboardGraphExplorerService(
        new CanonicalGraphQueryService,
    ));
    $responseMethod = (new ReflectionClass($controller))->getMethod('response');
    $responseMethod->setAccessible(true);

    return $responseMethod->invoke($controller, 'project-1', 'overview', [
        'type' => 'repository',
        'id' => 'scope-1',
    ], $result)->getData(true);
}

final class Task3ApiNeo4jClient implements Neo4jClient
{
    /** @var list<array{cypher:string,parameters:array<string,mixed>}> */
    public array $commands = [];

    public string $nodeKind = 'method';

    /** @param array{source_handle:string,target_handle:string} $handles */
    public function __construct(
        private readonly string $projectId,
        private readonly string $scopeId,
        private readonly string $version,
        private readonly array $handles,
    ) {}

    public function run(string $cypher, array $parameters = []): mixed
    {
        $this->commands[] = ['cypher' => $cypher, 'parameters' => $parameters];
        $source = [
            'external_id' => 'method:InvoiceService::charge',
            'public_handle' => $this->handles['source_handle'],
            'kind' => $this->nodeKind,
            'public_search_label' => 'InvoiceService::charge',
        ];
        $target = [
            'external_id' => 'method:InvoiceService::save',
            'public_handle' => $this->handles['target_handle'],
            'kind' => $this->nodeKind,
            'public_search_label' => 'InvoiceService::save',
        ];
        if (str_contains($cypher, 'RETURN version.public_handle_key_version AS public_handle_key_version')) {
            return [[
                'public_handle_key_version' => 'gh1',
                'public_handle_key_fingerprint' => hash_hmac('sha256', 'hades.graph.handle.v1', (string) config('app.key')),
            ]];
        }
        if (str_contains($cypher, 'traversal_schema_version')) {
            return [['traversal_schema_version' => 1]];
        }
        if (str_contains($cypher, 'db.index.fulltext.queryNodes')) {
            return [[
                'version_project_key' => 'gh1',
                'version_source_fingerprint' => hash_hmac('sha256', 'hades.graph.handle.v1', (string) config('app.key')),
                'node' => $source,
                'labels' => ['Method'],
                'score' => 0.987654321,
            ]];
        }
        if (str_contains($cypher, 'RETURN [n IN nodes(p)')) {
            return [[
                'nodes' => [
                    ['node' => $source, 'labels' => ['Method']],
                    ['node' => $target, 'labels' => ['Method']],
                ],
                'edges' => [[
                    'external_id' => 'edge:call',
                    'type' => 'CALLS_METHOD',
                    'source_id' => $source['external_id'],
                    'target_id' => $target['external_id'],
                ]],
            ]];
        }
        if (str_contains($cypher, 'MATCH (target:CanonicalGraphNode') && str_contains($cypher, '[*1..2]')) {
            return [[
                'node' => $source,
                'labels' => ['Method'],
                'distance' => 1,
                'family' => 'call',
                'edge_types' => ['CALLS_METHOD'],
            ]];
        }
        if (str_contains($cypher, 'UNWIND $frontier_ids AS frontier_id')) {
            return [[
                'source_id' => $source['external_id'],
                'node' => $target,
                'labels' => ['Method'],
                'edge' => [
                    'external_id' => 'edge:call',
                    'type' => 'CALLS_METHOD',
                    'source_id' => $source['external_id'],
                    'target_id' => $target['external_id'],
                ],
            ]];
        }
        if (str_contains($cypher, 'start.external_id = $start_external_id')) {
            return [[
                'node' => $source,
                'labels' => ['Method'],
                'match_fields' => [],
            ]];
        }
        if (str_contains($cypher, 'MATCH (node:CanonicalGraphNode')) {
            $handle = (string) ($parameters['public_handle'] ?? '');
            return [[
                'version_project_key' => 'gh1',
                'version_source_fingerprint' => hash_hmac('sha256', 'hades.graph.handle.v1', (string) config('app.key')),
                'node' => match ($handle) {
                    $this->handles['source_handle'] => $source,
                    $this->handles['target_handle'] => $target,
                    default => null,
                },
                'labels' => ['Method'],
            ]];
        }

        return [];
    }
}
