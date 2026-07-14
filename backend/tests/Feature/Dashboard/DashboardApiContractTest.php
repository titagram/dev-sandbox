<?php

use App\Models\User;
use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4jClientFactory;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DevBoardSeeder::class);
    $this->app->instance(Neo4jClientFactory::class, new class extends Neo4jClientFactory {
        public function client(): Neo4jClient
        {
            return new class implements Neo4jClient {
                public function run(string $cypher, array $parameters = []): mixed
                {
                    return [[
                        'public_handle_key_version' => 'gh1',
                        'public_handle_key_fingerprint' => hash('sha256', (string) config('app.key')),
                    ]];
                }
            };
        }
    });
});

it('denies non-admin users from managing plugin tokens through gates', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $pm = dashboardApiContractUserWithRole('PM');
    $developer = dashboardApiContractUserWithRole('Developer');
    $ids = createDashboardApiContractScenario();

    $this->actingAs($pm)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'pm token',
            'scopes' => ['projects.read'],
        ])
        ->assertForbidden();

    $this->actingAs($developer)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'dev token',
            'scopes' => ['projects.read'],
        ])
        ->assertForbidden();

    $token = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'gate test token',
            'scopes' => ['projects.read'],
        ])
        ->assertOk()
        ->json();

    $this->actingAs($pm)
        ->deleteJson("/api/dashboard/admin/plugin-tokens/{$token['id']}")
        ->assertForbidden();

    $this->actingAs($developer)
        ->postJson("/api/dashboard/admin/plugin-tokens/{$token['id']}/rotate")
        ->assertForbidden();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/devices')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['device_id']);

    expect(DB::table('audit_logs')
        ->where('action', 'permission.denied')
        ->count())->toBeGreaterThanOrEqual(3);
});

it('serves the generated frontend dashboard read contract', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/projects')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['project_id'])
        ->assertJsonPath('0.key', 'demo-project')
        ->assertJsonStructure([['repository_count', 'open_tasks', 'wiki_freshness']]);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}")
        ->assertOk()
        ->assertJsonPath('repositories.0.id', $ids['repository_id'])
        ->assertJsonPath('links.wiki', "/projects/{$ids['project_id']}/wiki")
        ->assertJsonPath('links.wiki_api', "/api/dashboard/projects/{$ids['project_id']}/wiki")
        ->assertJsonPath('policy.code_write_allowed', true)
        ->assertJsonPath('recent_run_ids.0', $ids['run_id']);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/kanban')
        ->assertOk()
        ->assertJsonPath('columns.1.id', 'ready')
        ->assertJsonPath("tasks.{$ids['task_id']}.linked_run_id", $ids['run_id']);

    $doneColumn = DB::table('kanban_columns')->where('status_key', 'done')->value('id');

    $this->actingAs($admin)
        ->patchJson("/api/dashboard/tasks/{$ids['task_id']}", ['column' => 'done'])
        ->assertOk()
        ->assertJsonPath('id', $ids['task_id'])
        ->assertJsonPath('column', 'done');

    expect(DB::table('tasks')->where('id', $ids['task_id'])->value('status_column_id'))->toBe($doneColumn);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/tasks/{$ids['task_id']}")
        ->assertOk()
        ->assertJsonPath('source.type', 'local_plugin_snapshot')
        ->assertJsonPath('linked_run_id', $ids['run_id']);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/runs')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['run_id'])
        ->assertJsonPath('0.type', 'genesis_import');

    $this->actingAs($admin)
        ->getJson("/api/dashboard/runs/{$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('id', $ids['run_id'])
        ->assertJsonPath('artifact_ids.0', $ids['artifact_id'])
        ->assertJsonPath('source.type', 'local_plugin_snapshot');

    $this->actingAs($admin)
        ->getJson('/api/dashboard/wiki')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['wiki_page_id'])
        ->assertJsonPath('0.has_evidence', true);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/wiki/pages/{$ids['wiki_page_id']}")
        ->assertOk()
        ->assertJsonPath('body_markdown', "# API Contract\n\nVerified from local analyzer.")
        ->assertJsonPath('evidence.0.kind', 'artifact_ref');

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/wiki/pages/{$ids['wiki_page_id']}")
        ->assertOk()
        ->assertJsonPath('project_id', $ids['project_id'])
        ->assertJsonPath('body_markdown', "# API Contract\n\nVerified from local analyzer.");

    $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('run_id', $ids['run_id'])
        ->assertJsonPath('stats.nodes', 2)
        ->assertJsonPath('nodes.0.source.type', 'local_analyzer');

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('run_id', $ids['run_id'])
        ->assertJsonPath('stats.nodes', 2)
        ->assertJsonPath('nodes.0.source.type', 'local_analyzer');

    $this->actingAs($admin)
        ->getJson('/api/dashboard/artifacts')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['artifact_id'])
        ->assertJsonPath('0.downloadable', true);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/runs/{$ids['run_id']}/artifacts/{$ids['artifact_id']}/download")
        ->assertOk()
        ->assertJsonPath('name', 'contract-graph.json')
        ->assertJsonPath('url', "/runs/{$ids['run_id']}/artifacts/{$ids['artifact_id']}/download");
});

it('serves the only canonical Hades graph without leaking local paths', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $localNodePath = '/srv/private/project/app/SecretController.php';
    $canonical = createDashboardCanonicalHadesGraph($ids['project_id'], [
        'path' => $localNodePath,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('stats.nodes', 1)
        ->assertJsonPath('source_scope.type', 'workspace_binding')
        ->assertJsonPath('source_scope.id', $canonical['binding_id'])
        ->assertJsonPath('graph_version', $canonical['graph_version'])
        ->assertJsonPath('quality', 'full')
        ->assertJsonPath('projection_status', 'ready')
        ->assertJsonCount(0, 'nodes')
        ->assertJsonCount(0, 'edges');

    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))
        ->not->toContain('/srv/private/project')
        ->not->toContain($localNodePath)
        ->not->toContain('method:DashboardApiReader::graph');
});

it('fails closed when a ready canonical projection has no active graph version', function (): void {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $canonical = createDashboardCanonicalHadesGraph($ids['project_id']);
    DB::table('canonical_graph_projections')
        ->where('project_id', $ids['project_id'])
        ->where('active_graph_version', $canonical['graph_version'])
        ->update(['active_graph_version' => null]);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('projection_status', 'graph_projection_rebuild_required')
        ->assertJsonPath('active_graph_version', null)
        ->assertJsonCount(0, 'nodes')
        ->assertJsonCount(0, 'edges');
});

it('withholds canonical preview handles when the stored projection key is rotated', function (): void {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $canonical = createDashboardCanonicalHadesGraph($ids['project_id']);
    $currentKey = '0123456789abcdef0123456789abcdef';
    config(['app.key' => $currentKey]);
    $this->app->bind(Neo4jClient::class, fn (): Neo4jClient => new class implements Neo4jClient {
        public function run(string $cypher, array $parameters = []): mixed
        {
            if (str_contains($cypher, 'CanonicalGraphVersion')) {
                return [[
                    'public_handle_key_version' => 'gh1',
                    'public_handle_key_fingerprint' => hash('sha256', 'previous-app-key'),
                ]];
            }

            return [];
        }
    });

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('projection_status', 'graph_projection_rebuild_required')
        ->assertJsonCount(0, 'nodes')
        ->assertJsonCount(0, 'edges');

    expect($canonical['graph_version'])->not->toBe('');
});

it('uses the Neo4j factory fallback and fails closed when the stored key is rotated', function (): void {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    createDashboardCanonicalHadesGraph($ids['project_id']);
    $currentKey = '0123456789abcdef0123456789abcdef';
    config(['app.key' => $currentKey]);
    $client = new class implements Neo4jClient {
        /** @var list<array{cypher: string, parameters: array<string, mixed>}> */
        public array $commands = [];

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->commands[] = ['cypher' => $cypher, 'parameters' => $parameters];

            return new CypherList([
                new CypherMap([
                    'public_handle_key_version' => 'gh1',
                    'public_handle_key_fingerprint' => hash('sha256', 'previous-app-key'),
                ]),
            ]);
        }
    };
    $this->app->instance(Neo4jClientFactory::class, new class($client) extends Neo4jClientFactory {
        public function __construct(private readonly Neo4jClient $client) {}

        public function client(): Neo4jClient
        {
            return $this->client;
        }
    });

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('projection_status', 'graph_projection_rebuild_required')
        ->assertJsonCount(0, 'nodes')
        ->assertJsonCount(0, 'edges');

    expect($client->commands)->toHaveCount(1)
        ->and($client->commands[0]['cypher'])
        ->toContain('RETURN version.public_handle_key_version AS public_handle_key_version')
        ->toContain('version.public_handle_key_fingerprint AS public_handle_key_fingerprint')
        ->not->toContain('RETURN version LIMIT 1')
        ->and($client->commands[0]['parameters'])->toMatchArray([
            'project_id' => $ids['project_id'],
            'source_scope_type' => 'workspace_binding',
            'source_scope_id' => DB::table('canonical_graph_projections')
                ->where('project_id', $ids['project_id'])
                ->value('source_scope_id'),
            'active_graph_version' => DB::table('canonical_graph_projections')
                ->where('project_id', $ids['project_id'])
                ->value('active_graph_version'),
        ]);
});

it('fails closed when the Neo4j factory query is unavailable', function (): void {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    createDashboardCanonicalHadesGraph($ids['project_id']);
    $this->app->instance(Neo4jClientFactory::class, new class extends Neo4jClientFactory {
        public function client(): Neo4jClient
        {
            return new class implements Neo4jClient {
                public function run(string $cypher, array $parameters = []): mixed
                {
                    throw new \RuntimeException('neo4j unavailable');
                }
            };
        }
    });

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('projection_status', 'graph_projection_rebuild_required')
        ->assertJsonCount(0, 'nodes')
        ->assertJsonCount(0, 'edges');
});

it('requires an explicit scope when multiple canonical graph scopes exist', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    createDashboardCanonicalHadesGraph($ids['project_id']);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('projection_status', 'scope_required')
        ->assertJsonPath('graph_version', null)
        ->assertJsonPath('stats.nodes', 0)
        ->assertJsonCount(2, 'scopes');

    expect($response->json('nodes'))->toBe([])
        ->and($response->json('edges'))->toBe([])
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('/srv/private/project');
});

it('bounds multi-scope metadata without loading graph artifact payloads', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $canonical = createDashboardCanonicalHadesGraph($ids['project_id']);
    $now = now();

    for ($index = 0; $index < 75; $index++) {
        $bindingId = (string) Str::ulid();
        DB::table('hades_workspace_bindings')->insert([
            'id' => $bindingId,
            'project_id' => $ids['project_id'],
            'hades_agent_id' => $canonical['agent_id'],
            'external_agent_id' => 'dashboard-'.$canonical['agent_id'],
            'workspace_fingerprint' => hash('sha256', 'scope-'.$bindingId),
            'display_path' => "/srv/private/project-{$index}",
            'status' => 'linked',
            'linked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('projection_status', 'scope_required')
        ->assertJsonPath('scopes_truncated', true)
        ->assertJsonCount(50, 'scopes');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $canonicalReads = collect($queries)->filter(function (array $query): bool {
        $sql = strtolower($query['query']);

        return str_contains($sql, 'hades_workspace_bindings')
            || str_contains($sql, 'repositories')
            || str_contains($sql, 'hades_agent_artifacts')
            || str_contains($sql, 'canonical_graph_projections');
    });
    $artifactReads = $canonicalReads->filter(
        fn (array $query): bool => str_contains(strtolower($query['query']), 'hades_agent_artifacts'),
    );

    expect($response->json('nodes'))->toBe([])
        ->and($response->json('edges'))->toBe([])
        ->and($canonicalReads->count())->toBeLessThanOrEqual(5)
        ->and($artifactReads)->toHaveCount(1)
        ->and(strtolower((string) $artifactReads->first()['query']))->not->toContain('"artifact" from');
});

it('describes the latest canonical artifact even when it is not projected and leaves empty scopes null', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $canonical = createDashboardCanonicalHadesGraph($ids['project_id']);
    $newArtifactId = (string) Str::ulid();
    $emptyBindingId = createDashboardCanonicalBinding($ids['project_id'], $canonical['agent_id']);
    $newer = now()->addMinute()->startOfSecond();
    $payload = json_encode([
        'graph_contract' => [
            'version' => 'hades.graph_artifact.v1',
            'extractor' => ['name' => 'hades-native-php', 'version' => '1', 'mode' => 'native', 'quality' => 'full', 'fallback_reason' => null],
            'coverage' => ['languages' => ['php'], 'files_total' => 0, 'files_analyzed' => 0, 'files_failed' => 0],
            'source' => ['branch' => 'main', 'head_commit' => str_repeat('b', 40)],
        ],
        'nodes' => [],
        'relationships' => [],
    ], JSON_THROW_ON_ERROR);

    DB::table('hades_agent_artifacts')->insert([
        'id' => $newArtifactId,
        'project_id' => $ids['project_id'],
        'hades_agent_id' => $canonical['agent_id'],
        'workspace_binding_id' => $canonical['binding_id'],
        'schema' => 'hades.php_graph.v1',
        'artifact' => $payload,
        'sha256' => hash('sha256', $payload),
        'truncated' => false,
        'redactions' => 0,
        'created_at' => $newer,
        'updated_at' => $newer,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('projection_status', 'scope_required');

    $scopes = collect($response->json('scopes'))->keyBy('id');
    expect($scopes[$canonical['binding_id']])
        ->toMatchArray([
            'type' => 'workspace_binding',
            'quality' => null,
            'head_commit' => null,
            'created_at' => (string) $newer,
            'projection_status' => 'unavailable',
        ])
        ->and($scopes[$emptyBindingId])
        ->toMatchArray([
            'type' => 'workspace_binding',
            'quality' => null,
            'head_commit' => null,
            'created_at' => null,
            'projection_status' => 'unavailable',
        ]);
});

it('sanitizes every local path form in canonical graph previews while preserving edge endpoints', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $paths = [
        '/srv/private/project/Posix.php',
        'C:\\Users\\private\\Windows.php',
        '\\\\server\\private\\Unc.php',
        'file:///home/private/FileUri.php',
    ];
    $nodes = [];
    foreach ($paths as $index => $path) {
        $nodes[] = [
            'id' => $path,
            'labels' => ['Method'],
            'properties' => [
                'name' => $paths[($index + 1) % count($paths)],
                'nested' => ['path' => $path],
            ],
        ];
    }
    $relationships = [
        ['id' => $paths[1], 'source_id' => $paths[0], 'target_id' => $paths[1], 'type' => 'calls', 'properties' => ['path' => $paths[2]]],
        ['id' => $paths[2], 'source_id' => $paths[1], 'target_id' => $paths[2], 'type' => 'imports'],
        ['id' => $paths[3], 'source_id' => $paths[2], 'target_id' => $paths[3], 'type' => 'calls'],
    ];
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes, $relationships);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('stats.nodes', 4)
        ->assertJsonPath('stats.edges', 3)
        ->assertJsonCount(0, 'nodes')
        ->assertJsonCount(0, 'edges');

    $publicStrings = collect(dashboardGraphJsonStrings($response->json()));
    $nodeIds = collect($response->json('nodes'))->pluck('id');
    $edges = collect($response->json('edges'));

    expect($publicStrings->filter(fn (string $value): bool => dashboardGraphLooksLikeLocalPath($value)))
        ->toBeEmpty()
        ->and($nodeIds->unique())->toHaveCount(0)
        ->and($edges->every(fn (array $edge): bool => $nodeIds->contains($edge['from']) && $nodeIds->contains($edge['to'])))
        ->toBeTrue();

    foreach ($paths as $path) {
        expect($publicStrings)->not->toContain($path);
    }
});

it('publishes only schema-approved route labels while pseudonymizing canonical identities', function (string $expectedLabel, array $properties) {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    $rawNodeId = 'route-internal:'.hash('sha256', $expectedLabel);
    $routeNode = [
        'id' => $rawNodeId,
        'labels' => ['Symbol', 'Route'],
        'properties' => $properties + [
            'kind' => 'route',
            'source' => ['ref' => '/repo/private/routes.php'],
        ],
    ];
    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');
    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => [$routeNode],
        'relationships' => [],
    ], JSON_THROW_ON_ERROR));
    $legacy = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('nodes.0.label', $expectedLabel)
        ->json();
    expect($legacy['nodes'][0]['id'])->toStartWith('hades-public-v1-node-')
        ->and($legacy['nodes'][0]['source']['ref'])->toBe($legacy['nodes'][0]['id'])
        ->and(json_encode($legacy, JSON_THROW_ON_ERROR))->not->toContain($rawNodeId)
        ->not->toContain('/repo/private/routes.php');

    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    createDashboardCanonicalHadesGraph($ids['project_id'], null, [$routeNode]);
    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('nodes.0.label', $expectedLabel)
        ->json();
    expect($response['nodes'][0]['id'])
        ->toStartWith('hades-public-v1-node-')
        ->and($response['nodes'][0]['source']['ref'])->toBe($response['nodes'][0]['id'])
        ->and(json_encode($response, JSON_THROW_ON_ERROR))->not->toContain($rawNodeId)
        ->not->toContain('/repo/private/routes.php');
})->with([
    'users route' => ['/users', ['path' => '/users']],
    'system route' => ['/system', ['uri' => '/system']],
    'media route' => ['/media', ['path' => '/media']],
    'data route' => ['/data', ['route' => '/data']],
    'method and route' => ['GET /users', ['name' => 'GET /users']],
]);

it('does not restore unsafe canonical route labels after preview sanitization', function (): void {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $unsafe = [
        '/home/private/Secret.php',
        '/data/private/secret',
        './src/Foo.ts',
        '../backend/app/Foo.php',
        'C:\\workspace\\Foo.php',
        'C:workspace\\Foo.php',
        '\\\\server\\share\\Foo.php',
        '\\\\?\\C:\\Foo.php',
        'file:///srv/private/Foo.php',
        'source=C:\\Foo.php',
        'src/Foo.ts',
    ];
    $nodes = array_map(
        static fn (string $path, int $index): array => [
            'id' => 'route:unsafe-'.$index,
            'labels' => ['Route'],
            'properties' => [
                'kind' => 'route',
                'path' => $path,
                'route_provenance' => 'route_registry',
            ],
        ],
        $unsafe,
        array_keys($unsafe),
    );
    $nodes[] = [
        'id' => 'route:api',
        'labels' => ['Route'],
        'properties' => ['kind' => 'route', 'path' => '/api/invoices/{id}', 'route_provenance' => 'route_registry'],
    ];
    $nodes[] = [
        'id' => 'route:well-known',
        'labels' => ['Route'],
        'properties' => ['kind' => 'route', 'path' => '/.well-known/openid-configuration', 'route_provenance' => 'route_registry'],
    ];
    $canonical = createDashboardCanonicalHadesGraph($ids['project_id']);
    $artifactId = DB::table('hades_agent_artifacts')
        ->where('workspace_binding_id', $canonical['binding_id'])
        ->value('id');
    DB::table('hades_agent_artifacts')->where('id', $artifactId)->update([
        'artifact' => json_encode([
            'language' => 'php',
            'routes' => [
                ['name' => 'api', 'method' => 'GET', 'uri' => 'api/invoices/{id}'],
                ['name' => 'well-known', 'method' => 'GET', 'uri' => '.well-known/openid-configuration'],
            ],
            'nodes' => $nodes,
            'relationships' => [],
        ], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();
    $labels = collect($response['nodes'])->pluck('label');
    $json = json_encode($response, JSON_THROW_ON_ERROR);

    expect($labels)->toContain('/api/invoices/{id}', '/.well-known/openid-configuration')
        ->and($json)->not->toContain('/home/private/Secret.php')
        ->not->toContain('/data/private/secret')
        ->not->toContain('C:\\workspace\\Foo.php')
        ->not->toContain('file:///srv/private/Foo.php')
        ->not->toContain('source=C:\\Foo.php')
        ->and($labels->intersect($unsafe))->toBeEmpty();
});

it('shows trusted non-api multi-segment routes and hides untrusted canonical paths', function (): void {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $canonical = createDashboardCanonicalHadesGraph($ids['project_id']);
    $artifactId = DB::table('hades_agent_artifacts')
        ->where('workspace_binding_id', $canonical['binding_id'])
        ->value('id');
    $payload = [
        'language' => 'php',
        'routes' => [
            ['name' => 'wiki', 'method' => 'GET', 'uri' => 'projects/{id}/wiki'],
        ],
        'nodes' => [
            [
                'id' => 'route:wiki',
                'kind' => 'route',
                'properties' => ['uri' => 'projects/{id}/wiki', 'route_provenance' => 'client_claim'],
            ],
            [
                'id' => 'route:unproven-wiki',
                'kind' => 'route',
                'properties' => ['uri' => '/projects/{id}/wiki', 'route_provenance' => 'client_claim'],
            ],
        ],
        'relationships' => [],
    ];
    DB::table('hades_agent_artifacts')->where('id', $artifactId)->update([
        'artifact' => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();
    $labels = collect($response['nodes'])->pluck('label');

    expect($labels)->toContain('/projects/{id}/wiki')
        ->and($labels->filter(fn (mixed $label): bool => $label === '/projects/{id}/wiki'))->toHaveCount(1);
});

it('publishes a leading-root PHP FQCN in the canonical graph preview', function (): void {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    createDashboardCanonicalHadesGraph($ids['project_id'], null, [
        [
            'id' => 'class:rooted-fqcn',
            'labels' => ['Class'],
            'properties' => ['kind' => 'class', 'name' => '\\App\\Services\\InvoiceService'],
        ],
        [
            'id' => 'class:windows-path',
            'labels' => ['Class'],
            'properties' => ['kind' => 'class', 'name' => 'C:\\workspace\\Foo.php'],
        ],
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();
    $labels = collect($response['nodes'])->pluck('label');

    expect($labels)->toContain('\\App\\Services\\InvoiceService')
        ->and($labels)->not->toContain('C:\\workspace\\Foo.php');
});

it('fails closed for conflicting graph node semantics independent of producer label order', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $privatePath = '/app/private/Secret.php';
    $privateVerbPath = 'GET /home/private/Secret.php';
    $nodes = [
        ['id' => 'conflict:file-route-a', 'labels' => ['File', 'Route'], 'properties' => ['kind' => 'route', 'name' => $privatePath]],
        ['id' => 'conflict:file-route-b', 'labels' => ['Route', 'File'], 'properties' => ['kind' => 'route', 'name' => $privatePath]],
        ['id' => 'conflict:function-route', 'labels' => ['Function', 'Route'], 'properties' => ['kind' => 'route', 'name' => $privateVerbPath]],
        ['id' => 'conflict:unknown-route', 'labels' => ['UnexpectedProducer', 'Route'], 'properties' => ['kind' => 'route', 'name' => $privatePath]],
        ['id' => 'route:safe', 'labels' => ['Symbol', 'Route'], 'properties' => ['kind' => 'http_endpoint', 'path' => '/api/orders']],
    ];
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();
    $publicNodes = collect($response['nodes']);

    expect($publicNodes->where('kind', 'unknown'))->toHaveCount(0)
        ->and($publicNodes->where('kind', 'route'))->toHaveCount(1)
        ->and($publicNodes->firstWhere('kind', 'route')['label'])->toBe('/api/orders')
        ->and(json_encode($response, JSON_THROW_ON_ERROR))->not->toContain($privatePath)
        ->not->toContain($privateVerbPath);
});

it('never republishes raw node external or source identities as public labels', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    $rawIdentities = [
        'InternalSecretToken',
        'App\\Services\\OrderService',
        'ExternalProducerIdentity',
        'SourceIdentity',
        'SecretFile.php',
        'CaseNormalizedToken',
    ];
    $nodes = [
        ['id' => $rawIdentities[0], 'labels' => ['Function'], 'properties' => ['kind' => 'function', 'name' => $rawIdentities[0]]],
        ['id' => $rawIdentities[1], 'labels' => ['Class'], 'properties' => ['kind' => 'class', 'name' => $rawIdentities[1]]],
        ['id' => 'function:external', 'labels' => ['Function'], 'properties' => ['kind' => 'function', 'external_id' => $rawIdentities[2], 'name' => $rawIdentities[2]]],
        ['id' => 'function:source', 'labels' => ['Function'], 'properties' => ['kind' => 'function', 'source' => ['ref' => $rawIdentities[3]], 'name' => $rawIdentities[3]]],
        ['id' => $rawIdentities[4], 'labels' => ['File'], 'properties' => ['kind' => 'file', 'name' => '/repo/private/'.$rawIdentities[4]]],
        ['id' => $rawIdentities[5], 'labels' => ['Function'], 'properties' => ['kind' => 'function', 'name' => strtolower($rawIdentities[5])]],
        ['id' => 'function:public', 'labels' => ['Symbol', 'Function'], 'properties' => ['kind' => 'method', 'name' => 'PublicReadableMethod']],
    ];
    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');
    Storage::disk('local')->put($storagePath, json_encode(['nodes' => $nodes, 'relationships' => []], JSON_THROW_ON_ERROR));

    $legacy = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->json();

    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes);
    $canonical = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();

    foreach ([$legacy, $canonical] as $response) {
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        foreach ([...$rawIdentities, strtolower($rawIdentities[5])] as $rawIdentity) {
            expect($json)->not->toContain($rawIdentity);
        }
        expect(collect($response['nodes'])->pluck('label'))->toContain('PublicReadableMethod');
    }
});

it('keeps raw identity provenance private across canonical and legacy graph boundaries', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    $privateLabels = [
        'TopLevelExternalService',
        'WindowsSourceService',
        'PosixSourceFunction',
        'UncSourceClass',
        'FileUriModule',
        'DuplicateExternalFunction',
    ];
    $nodes = [
        [
            'id' => 'service:external',
            'external_id' => $privateLabels[0],
            'labels' => ['Service'],
            'properties' => ['kind' => 'service', 'name' => $privateLabels[0]],
        ],
        [
            'id' => 'service:windows-source',
            'source' => ['ref' => 'C:\\Users\\private\\'.$privateLabels[1]],
            'labels' => ['Service'],
            'properties' => ['kind' => 'service', 'name' => $privateLabels[1]],
        ],
        [
            'id' => 'function:posix-source',
            'source' => ['ref' => '/srv/private/project/'.$privateLabels[2]],
            'labels' => ['Function'],
            'properties' => ['kind' => 'function', 'name' => $privateLabels[2]],
        ],
        [
            'id' => 'class:unc-source',
            'source' => ['path' => '\\\\server\\private\\'.$privateLabels[3]],
            'labels' => ['Class'],
            'properties' => ['kind' => 'class', 'name' => $privateLabels[3]],
        ],
        [
            'id' => 'module:file-uri-source',
            'properties' => [
                'kind' => 'module',
                'name' => $privateLabels[4],
                'source' => ['ref' => 'file:///home/private/'.$privateLabels[4]],
            ],
            'labels' => ['Module'],
        ],
        [
            'id' => 'function:public-readable',
            'external_id' => 'different-private-identity',
            'labels' => ['Function'],
            'properties' => ['kind' => 'function', 'name' => 'PublicReadableMethod'],
        ],
        [
            'id' => 'function:duplicate-identity',
            'external_id' => $privateLabels[5],
            'labels' => ['Function'],
            'properties' => ['kind' => 'function', 'name' => $privateLabels[5]],
        ],
        [
            'id' => 'function:duplicate-identity',
            'labels' => ['Function'],
            'properties' => ['kind' => 'function', 'name' => 'IgnoredDuplicate'],
        ],
        [
            'id' => 'route:orders',
            'source' => ['ref' => '/srv/private/project/routes.php'],
            'labels' => ['Route'],
            'properties' => ['kind' => 'route', 'path' => '/api/orders'],
        ],
    ];
    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');
    Storage::disk('local')->put($storagePath, json_encode(['nodes' => $nodes, 'relationships' => []], JSON_THROW_ON_ERROR));

    $legacy = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->json();

    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes);
    $canonical = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();

    foreach ([$legacy, $canonical] as $response) {
        $labels = collect($response['nodes'])->pluck('label');
        $json = json_encode($response, JSON_THROW_ON_ERROR);

        foreach ($privateLabels as $privateLabel) {
            expect($labels)->not->toContain($privateLabel)
                ->and($json)->not->toContain($privateLabel);
        }
        expect($labels)->toContain('PublicReadableMethod')
            ->toContain('/api/orders');
    }
});

it('treats direct path fields as private identity provenance across canonical and legacy graphs', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    $privateLabels = [
        'PosixPrivateService',
        'WindowsPrivateClass',
        'UncPrivateFunction',
        'FileUriPrivateModule',
        'BarePrivateService',
        'BarePropertiesPrivateClass',
    ];
    $privatePaths = [
        '/srv/private/project/'.$privateLabels[0].'.php',
        'C:\\Users\\private\\'.$privateLabels[1].'.php',
        '\\\\server\\private\\'.$privateLabels[2].'.php',
        'file:///home/private/'.$privateLabels[3].'.php',
        $privateLabels[4].'.php',
        $privateLabels[5].'.php',
    ];
    $nodes = [
        [
            'id' => 'service:posix-path',
            'path' => $privatePaths[0],
            'labels' => ['Service'],
            'properties' => ['kind' => 'service', 'name' => $privateLabels[0]],
        ],
        [
            'id' => 'class:windows-path',
            'labels' => ['Class'],
            'properties' => [
                'kind' => 'class',
                'name' => $privateLabels[1],
                'path' => $privatePaths[1],
            ],
        ],
        [
            'id' => 'function:unc-path',
            'path' => $privatePaths[2],
            'labels' => ['Function'],
            'properties' => ['kind' => 'function', 'name' => $privateLabels[2]],
        ],
        [
            'id' => 'module:file-uri-path',
            'labels' => ['Module'],
            'properties' => [
                'kind' => 'module',
                'name' => $privateLabels[3],
                'path' => $privatePaths[3],
            ],
        ],
        [
            'id' => 'service:bare-path',
            'path' => $privatePaths[4],
            'labels' => ['Service'],
            'properties' => ['kind' => 'service', 'name' => $privateLabels[4]],
        ],
        [
            'id' => 'class:bare-properties-path',
            'labels' => ['Class'],
            'properties' => [
                'kind' => 'class',
                'name' => $privateLabels[5],
                'path' => $privatePaths[5],
            ],
        ],
        [
            'id' => 'service:public-readable',
            'path' => 'DifferentPrivateIdentity.php',
            'labels' => ['Service'],
            'properties' => ['kind' => 'service', 'name' => 'PublicReadableService'],
        ],
    ];
    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');
    Storage::disk('local')->put($storagePath, json_encode(['nodes' => $nodes, 'relationships' => []], JSON_THROW_ON_ERROR));

    $legacy = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->json();

    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes);
    $canonical = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();

    foreach ([$legacy, $canonical] as $response) {
        $labels = collect($response['nodes'])->pluck('label');
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        foreach ([...$privateLabels, ...$privatePaths] as $privateValue) {
            expect($labels)->not->toContain($privateValue)
                ->and($json)->not->toContain($privateValue);
        }
        expect($labels)->toContain('PublicReadableService');
    }
});

it('normalizes code namespace aliases only when comparing private graph identities', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    $privateLabels = [
        'app.private.orderservice',
        'app\\private\\customerservice',
        'Company\\Private\\TokenClass',
        'Company::Private::ReverseTokenClass',
    ];
    $privateIdentities = [
        'App\\Private\\OrderService',
        'App.Private.CustomerService',
        'Company::Private::TokenClass',
        'Company\\Private\\ReverseTokenClass',
    ];
    $nodes = [
        [
            'id' => $privateIdentities[0],
            'labels' => ['Service'],
            'properties' => ['kind' => 'service', 'name' => $privateLabels[0]],
        ],
        [
            'id' => $privateIdentities[1],
            'labels' => ['Service'],
            'properties' => ['kind' => 'service', 'name' => $privateLabels[1]],
        ],
        [
            'id' => $privateIdentities[2],
            'labels' => ['Class'],
            'properties' => ['kind' => 'class', 'name' => $privateLabels[2]],
        ],
        [
            'id' => $privateIdentities[3],
            'labels' => ['Class'],
            'properties' => ['kind' => 'class', 'name' => $privateLabels[3]],
        ],
        [
            'id' => 'App\\Private\\OrderRepository',
            'labels' => ['Service'],
            'properties' => ['kind' => 'service', 'name' => 'App.Public.OrderService'],
        ],
        [
            'id' => 'module:public-domain',
            'external_id' => 'internal.example.net',
            'labels' => ['Module'],
            'properties' => ['kind' => 'module', 'name' => 'internal.example.com'],
        ],
    ];
    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');
    Storage::disk('local')->put($storagePath, json_encode(['nodes' => $nodes, 'relationships' => []], JSON_THROW_ON_ERROR));

    $legacy = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->json();

    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes);
    $canonical = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();

    foreach ([$legacy, $canonical] as $response) {
        $labels = collect($response['nodes'])->pluck('label');
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        foreach ([...$privateLabels, ...$privateIdentities] as $privateValue) {
            expect($labels)->not->toContain($privateValue)
                ->and($json)->not->toContain($privateValue);
        }
        expect($labels)
            ->toContain('App.Public.OrderService')
            ->toContain('internal.example.com');
    }
});

it('uses a closed semantic allowlist for arbitrary producer node kinds', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $nodes = [
        ['id' => 'alien:one', 'labels' => ['UnexpectedProducer'], 'properties' => ['kind' => 'alien', 'name' => 'InternalSecretToken']],
        ['id' => 'alien:two', 'labels' => ['Symbol'], 'properties' => ['kind' => 'arbitrary_producer', 'name' => 'AnotherSecretToken']],
        ['id' => 'service:known', 'labels' => ['Symbol', 'Service'], 'properties' => ['kind' => 'service', 'name' => 'KnownPublicService']],
    ];
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();
    $publicNodes = collect($response['nodes']);
    $json = json_encode($response, JSON_THROW_ON_ERROR);

    expect($publicNodes->where('kind', 'unknown'))->toHaveCount(0)
        ->and($publicNodes->where('kind', 'service'))->toHaveCount(1)
        ->and($publicNodes->firstWhere('kind', 'service')['label'])->toBe('KnownPublicService')
        ->and($json)->not->toContain('InternalSecretToken')
        ->not->toContain('AnotherSecretToken');
});

it('minimizes non-route and unknown canonical nodes and never publishes raw graph identities', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $unsafeValues = [
        '/app/private/File.php',
        '/repo/private/Module.php',
        'arbitrary-prefix:/secret/Controller.php',
        'C:\\Users\\private\\Windows.php',
        '\\\\server\\private\\Unc.php',
        'file:///private/Uri.php',
        'unknown arbitrary display text',
        'symbol@/etc/passwd',
        'symbol#/root/secret',
    ];
    $nodes = [
        ['id' => 'file:raw-internal', 'labels' => ['File'], 'properties' => ['kind' => 'file', 'name' => $unsafeValues[0], 'path' => $unsafeValues[0]]],
        ['id' => 'module:raw-internal', 'labels' => ['Module'], 'properties' => ['kind' => 'module', 'name' => $unsafeValues[1]]],
        ['id' => 'class:raw-internal', 'labels' => ['Class'], 'properties' => ['kind' => 'class', 'name' => $unsafeValues[2]]],
        ['id' => 'function:raw-internal', 'labels' => ['Function'], 'properties' => ['kind' => 'function', 'name' => $unsafeValues[3]]],
        ['id' => 'method:raw-internal', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => $unsafeValues[4]]],
        ['id' => 'model:raw-internal', 'labels' => ['Model'], 'properties' => ['kind' => 'model', 'name' => $unsafeValues[5]]],
        ['id' => 'unknown:raw-internal', 'labels' => ['UnexpectedProducerType'], 'properties' => ['kind' => 'unexpected_kind', 'name' => $unsafeValues[6], 'nested' => ['source' => ['ref' => '/private/source.php']]]],
        ['id' => 'unknown-at:raw-internal', 'labels' => ['UnexpectedProducerType'], 'properties' => ['kind' => 'unexpected_kind', 'name' => $unsafeValues[7]]],
        ['id' => 'unknown-hash:raw-internal', 'labels' => ['UnexpectedProducerType'], 'properties' => ['kind' => 'unexpected_kind', 'name' => $unsafeValues[8]]],
    ];
    $relationships = [
        ['id' => 'raw-edge-alpha', 'source_id' => 'file:raw-internal', 'target_id' => 'module:raw-internal', 'type' => 'imports'],
        ['id' => 'raw-edge-beta', 'source_id' => 'class:raw-internal', 'target_id' => 'function:raw-internal', 'type' => 'calls'],
    ];
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes, $relationships);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonCount(0, 'nodes')
        ->assertJsonCount(0, 'edges')
        ->json();
    $json = json_encode($response, JSON_THROW_ON_ERROR);
    $rawIdentities = [
        ...array_column($nodes, 'id'),
        ...array_column($relationships, 'id'),
    ];

    foreach ([...$unsafeValues, ...$rawIdentities, '/private/source.php'] as $privateValue) {
        expect($json)->not->toContain($privateValue);
    }
    expect(collect($response['nodes'])->every(
        fn (array $node): bool => str_starts_with($node['id'], 'hades-public-v1-node-')
            && ($node['source']['ref'] ?? null) === $node['id'],
    ))->toBeTrue()
        ->and(collect($response['edges'])->every(
            fn (array $edge): bool => str_starts_with($edge['id'], 'hades-public-v1-edge-'),
        ))->toBeTrue();
    assertDashboardGraphIdentityInvariants($response);
});

it('reports real canonical unknown and legacy-label counts while keeping the default canvas resolved', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    $nodes = [];

    for ($index = 0; $index < 70; $index++) {
        $nodes[] = [
            'id' => "unknown:legacy-{$index}",
            'labels' => ['UnexpectedProducerType'],
            'properties' => [
                'kind' => 'unexpected_kind',
                'name' => "hades-public-v1-node-legacy-{$index}",
            ],
        ];
    }

    for ($index = 70; $index < 145; $index++) {
        $nodes[] = [
            'id' => "service:legacy-{$index}",
            'labels' => ['Service'],
            'properties' => [
                'kind' => 'service',
                'name' => "hades-public-v1-node-legacy-{$index}",
            ],
        ];
    }

    for ($index = 0; $index < 55; $index++) {
        $nodes[] = [
            'id' => "function:resolved-{$index}",
            'labels' => ['Function'],
            'properties' => [
                'kind' => 'function',
                'name' => "ResolvedService{$index}",
            ],
        ];
    }

    $relationships = [
        ['id' => 'resolved-edge', 'source_id' => 'function:resolved-0', 'target_id' => 'function:resolved-1', 'type' => 'CALLS'],
        ['id' => 'unknown-edge', 'source_id' => 'unknown:legacy-0', 'target_id' => 'function:resolved-0', 'type' => 'CALLS'],
        ['id' => 'legacy-edge', 'source_id' => 'service:legacy-70', 'target_id' => 'function:resolved-0', 'type' => 'CALLS'],
        ['id' => 'unknown-unknown-edge', 'source_id' => 'unknown:legacy-1', 'target_id' => 'unknown:legacy-2', 'type' => 'CALLS'],
    ];
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes, $relationships);

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();
    $canvasIds = array_fill_keys(array_column($response['nodes'], 'id'), true);

    expect(count($nodes))->toBe(200)
        ->and($response['stats']['nodes'])->toBe(200)
        ->and($response['stats']['unknown_kind_count'])->toBe(70)
        ->and($response['stats']['missing_label_count'])->toBe(145)
        ->and($response['stats']['excluded_node_count'])->toBe(145)
        ->and($response['stats']['excluded_node_count'])->toBeGreaterThanOrEqual(max(
            $response['stats']['unknown_kind_count'],
            $response['stats']['missing_label_count'],
        ))
        ->and($response['nodes'])->toHaveCount(55)
        ->and(collect($response['nodes'])->every(
            fn (array $node): bool => ($node['kind'] ?? 'unknown') !== 'unknown'
                && is_string($node['label'] ?? null)
                && ! str_starts_with($node['label'], 'hades-public-'),
        ))->toBeTrue()
        ->and(collect($response['edges'])->every(
            fn (array $edge): bool => isset($canvasIds[$edge['from']], $canvasIds[$edge['to']]),
        ))->toBeTrue()
        ->and($response['edges'])->toHaveCount(1);
});

it('omits arbitrary canonical identifiers even when they do not look like local paths', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();
    ['unsafe' => $unsafeIdentifiers, 'safe' => $safeIdentifiers, 'nodes' => $nodes, 'relationships' => $relationships] = dashboardGraphPathFixture();
    createDashboardCanonicalHadesGraph($ids['project_id'], null, $nodes, $relationships);

    $canonical = $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->json();
    assertDashboardGraphResponseHasNoLocalPaths($canonical, $unsafeIdentifiers);
    foreach ($safeIdentifiers as $safeIdentifier) {
        expect(json_encode($canonical, JSON_THROW_ON_ERROR))->not->toContain($safeIdentifier);
    }
});

it('omits arbitrary legacy identifiers even when they do not look like local paths', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    ['unsafe' => $unsafeIdentifiers, 'safe' => $safeIdentifiers, 'nodes' => $nodes, 'relationships' => $relationships] = dashboardGraphPathFixture();
    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');
    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => $nodes,
        'relationships' => $relationships,
    ], JSON_THROW_ON_ERROR));
    $legacy = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->json();
    assertDashboardGraphResponseHasNoLocalPaths($legacy, $unsafeIdentifiers);
    foreach ($safeIdentifiers as $safeIdentifier) {
        expect(json_encode($legacy, JSON_THROW_ON_ERROR))->not->toContain($safeIdentifier);
    }
});

it('assigns deterministic collision safe graph identifiers independent of input order', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    $unsafeNodeId = '/srv/private/collision.php';
    $oldNodeHashCollision = 'node-'.substr(hash('sha256', $unsafeNodeId), 0, 24);
    $reservedNodeId = 'hades-public-v1-node-'.str_repeat('a', 64);
    $unsafeEdgeId = 'file:/home/private/collision-edge.php';
    $oldEdgeHashCollision = 'edge-'.substr(hash('sha256', $unsafeEdgeId), 0, 24);
    $reservedEdgeId = 'hades-public-v1-edge-'.str_repeat('b', 64);
    $nodes = [
        ['id' => $unsafeNodeId, 'labels' => ['Method'], 'properties' => ['name' => 'unsafe']],
        ['id' => $oldNodeHashCollision, 'labels' => ['Method'], 'properties' => ['name' => 'old-collision']],
        ['id' => $reservedNodeId, 'labels' => ['Method'], 'properties' => ['name' => 'reserved']],
        ['id' => 'method:safeA', 'labels' => ['Method'], 'properties' => ['name' => 'safeA']],
        ['id' => 'method:safeB', 'labels' => ['Method'], 'properties' => ['name' => 'safeB']],
    ];
    $relationships = [
        ['id' => $unsafeEdgeId, 'source_id' => $unsafeNodeId, 'target_id' => $oldNodeHashCollision, 'type' => 'calls'],
        ['id' => $oldEdgeHashCollision, 'source_id' => $oldNodeHashCollision, 'target_id' => $reservedNodeId, 'type' => 'imports'],
        ['id' => $reservedEdgeId, 'source_id' => $reservedNodeId, 'target_id' => 'method:safeA', 'type' => 'calls'],
        ['id' => 'duplicate-edge', 'source_id' => 'method:safeA', 'target_id' => 'method:safeB', 'type' => 'calls'],
        ['id' => 'duplicate-edge', 'source_id' => 'method:safeB', 'target_id' => $unsafeNodeId, 'type' => 'calls'],
        ['source_id' => $unsafeNodeId, 'target_id' => 'method:safeA', 'type' => 'extends'],
    ];
    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');

    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => $nodes,
        'relationships' => $relationships,
    ], JSON_THROW_ON_ERROR));
    $forward = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->json();

    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => array_reverse($nodes),
        'relationships' => array_reverse($relationships),
    ], JSON_THROW_ON_ERROR));
    $reverse = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->json();

    assertDashboardGraphIdentityInvariants($forward);
    assertDashboardGraphIdentityInvariants($reverse);
    expect(dashboardGraphSemanticNodeIdMap($forward))->toBe(dashboardGraphSemanticNodeIdMap($reverse))
        ->and(dashboardGraphSemanticEdgeIdMap($forward))->toBe(dashboardGraphSemanticEdgeIdMap($reverse));
});

it('reports canonical graphs as unavailable when the project has no source scope', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    DB::table('repositories')->where('project_id', $ids['project_id'])->delete();

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph")
        ->assertOk()
        ->assertJsonPath('projection_status', 'unavailable')
        ->assertJsonPath('graph_version', null)
        ->assertJsonPath('stats.nodes', 0)
        ->assertJsonCount(0, 'scopes');
});

it('serves dashboard run, admin, and system operations through the adapter contract', function () {
    config(['services.devboard.graph_import_mode' => 'fake']);

    $admin = dashboardApiContractUserWithRole('Admin');
    $developer = dashboardApiContractUserWithRole('Developer');
    $pm = dashboardApiContractUserWithRole('PM');
    $ids = createDashboardApiContractScenario(retryable: true);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/runs/{$ids['run_id']}/review")
        ->assertOk()
        ->assertJsonPath('id', $ids['run_id'])
        ->assertJsonPath('status', 'reviewed')
        ->assertJsonPath('reviewed_by', $developer->name);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/runs/{$ids['run_id']}/retry-import")
        ->assertForbidden();

    $this->actingAs($developer)
        ->postJson("/api/dashboard/runs/{$ids['run_id']}/retry-import")
        ->assertOk()
        ->assertJsonPath('id', $ids['run_id'])
        ->assertJsonPath('graph_status', 'complete');

    $created = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'frontend adapter token',
            'scopes' => ['projects.read'],
        ])
        ->assertOk()
        ->assertJsonPath('name', 'frontend adapter token')
        ->assertJsonStructure(['plain_token', 'prefix'])
        ->json();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/plugin-tokens')
        ->assertOk()
        ->assertJsonMissing(['plain_token' => $created['plain_token']])
        ->assertJsonPath('0.id', $created['id']);

    $this->actingAs($admin)
        ->postJson("/api/dashboard/admin/plugin-tokens/{$created['id']}/rotate")
        ->assertOk()
        ->assertJsonPath('id', $created['id'])
        ->assertJsonStructure(['plain_token']);

    $this->actingAs($admin)
        ->deleteJson("/api/dashboard/admin/plugin-tokens/{$created['id']}")
        ->assertNoContent();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/devices')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['device_id'])
        ->assertJsonPath('0.status', 'active');

    $this->actingAs($admin)
        ->deleteJson("/api/dashboard/admin/devices/{$ids['device_id']}")
        ->assertNoContent();

    $this->actingAs($pm)
        ->getJson('/api/dashboard/system')
        ->assertForbidden();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/system')
        ->assertOk()
        ->assertJsonPath('retention.artifact_retention_days', 90)
        ->assertJsonPath('audit_export_available', true);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/system/artifact-retention', [
            'retention_days' => 90,
            'auto_purge_enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('last_operation.status', 'ok');

    $this->actingAs($admin)
        ->postJson('/api/dashboard/system/audit-exports', ['range_days' => 30])
        ->assertOk()
        ->assertJsonPath('last_operation.status', 'ok');
});

it('serves a bounded graph preview with total stats and analyzer relationship keys', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    $nodes = [];
    $relationships = [];

    for ($index = 0; $index < 50; $index++) {
        $nodes[] = [
            'id' => "module:unconnected{$index}",
            'labels' => ['Module'],
            'properties' => ['name' => "unconnected{$index}"],
        ];
    }

    for ($index = 0; $index < 250; $index++) {
        $nodes[] = [
            'id' => "function:handler{$index}",
            'labels' => ['Function'],
            'properties' => ['name' => "handler{$index}"],
        ];
    }

    for ($index = 0; $index < 249; $index++) {
        $relationships[] = [
            'id' => "rel-{$index}",
            'type' => 'CALLS',
            'source_id' => "function:handler{$index}",
            'target_id' => 'function:handler'.($index + 1),
        ];
    }

    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');
    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => $nodes,
        'relationships' => $relationships,
    ], JSON_THROW_ON_ERROR));

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('stats.nodes', 300)
        ->assertJsonPath('stats.edges', 249)
        ->json();

    expect($response['nodes'])->toHaveCount(200);
    expect($response['edges'])->toHaveCount(199);
    expect($response['nodes'][0]['id'])->toStartWith('hades-public-v1-node-');
    expect($response['nodes'][0]['label'])->toBe('handler0');
    expect($response['nodes'][0]['degree'])->toBeGreaterThan(0);
    expect($response['edges'][0]['from'])->toBe($response['nodes'][0]['id']);
    expect($response['edges'][0]['to'])->toBe($response['nodes'][1]['id']);
    expect($response['edges'][0]['id'])->toStartWith('hades-public-v1-edge-');
    expect(json_encode($response, JSON_THROW_ON_ERROR))
        ->not->toContain('function:handler0')
        ->not->toContain('rel-0');
});

function dashboardApiContractUserWithRole(string $roleName): User
{
    $user = User::factory()->create(['status' => 'active']);
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * @return array<string, string>
 */
function createDashboardApiContractScenario(bool $retryable = false): array
{
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $readyColumnId = DB::table('kanban_columns')->where('status_key', 'ready')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $taskId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $genesisId = (string) Str::ulid();
    $wikiPageId = (string) Str::ulid();
    $wikiRevisionId = (string) Str::ulid();
    $now = now();
    $storagePath = 'devboard/test/contract-graph.json';

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $adminId,
        'name' => 'Contract Device',
        'fingerprint_hash' => 'sha256:contract-device',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.9.4',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:contract-workspace',
        'display_path' => '/workspace/target-repo',
        'current_branch' => 'feature/dashboard-api',
        'last_head_sha' => 'def456',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Expose dashboard adapter API',
        'description' => 'Return generated frontend contract data from Laravel.',
        'status_column_id' => $readyColumnId,
        'priority' => 'high',
        'risk_level' => 'medium',
        'owner_user_id' => $adminId,
        'created_by_user_id' => $adminId,
        'due_at' => $now->copy()->addDay(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'task_id' => $taskId,
        'device_id' => $deviceId,
        'started_by_user_id' => $adminId,
        'runtime_profile' => 'agent_plugin',
        'status' => $retryable ? 'failed' : 'finished',
        'branch' => 'feature/dashboard-api',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Dashboard adapter contract fixture.',
        'risk_level' => 'medium',
        'started_at' => $now->copy()->subMinutes(10),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => [
            ['id' => 'route:dashboard-api', 'labels' => ['Route'], 'properties' => ['path' => '/api/dashboard/runs']],
            ['id' => 'class:DashboardApiReader', 'labels' => ['Class'], 'properties' => ['name' => 'DashboardApiReader']],
        ],
        'relationships' => [
            ['id' => 'edge-1', 'type' => 'routes_to', 'from' => 'route:dashboard-api', 'to' => 'class:DashboardApiReader'],
        ],
    ], JSON_THROW_ON_ERROR));

    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'artifact_type' => 'graph_snapshot',
        'storage_path' => $storagePath,
        'sha256' => hash('sha256', Storage::disk('local')->get($storagePath)),
        'size_bytes' => Storage::disk('local')->size($storagePath),
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'imported',
        'producer' => 'devboard-python-plugin',
        'metadata' => json_encode(['source_type' => 'local_plugin_snapshot'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $snapshotId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'feature/dashboard-api',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => $artifactId,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    DB::table('genesis_imports')->insert([
        'id' => $genesisId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'status' => $retryable ? 'failed' : 'active',
        'manifest_artifact_id' => null,
        'snapshot_id' => $snapshotId,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'started_at' => $now->copy()->subMinutes(10),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_pages')->insert([
        'id' => $wikiPageId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'slug' => 'technical/api-contract',
        'title' => 'API Contract',
        'page_type' => 'technical',
        'current_revision_id' => null,
        'source_status' => 'verified_from_code',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_revisions')->insert([
        'id' => $wikiRevisionId,
        'wiki_page_id' => $wikiPageId,
        'author_user_id' => $adminId,
        'author_device_id' => $deviceId,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
        'content_markdown' => "# API Contract\n\nVerified from local analyzer.",
        'evidence_refs' => json_encode([
            ['type' => 'artifact', 'artifact_id' => $artifactId, 'description' => 'contract-graph.json'],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    DB::table('wiki_pages')->where('id', $wikiPageId)->update([
        'current_revision_id' => $wikiRevisionId,
        'updated_at' => $now,
    ]);

    DB::table('run_events')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $runId,
        'event_type' => 'run.finished',
        'severity' => $retryable ? 'error' : 'info',
        'message' => 'Contract fixture run event.',
        'payload' => json_encode(['risk_triggers' => ['dashboard_contract']], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    return [
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'task_id' => $taskId,
        'run_id' => $runId,
        'artifact_id' => $artifactId,
        'snapshot_id' => $snapshotId,
        'wiki_page_id' => $wikiPageId,
    ];
}

/** @return array{agent_id: string, binding_id: string, graph_version: string} */
function createDashboardCanonicalHadesGraph(
    string $projectId,
    ?array $nodeProperties = null,
    ?array $nodes = null,
    ?array $relationships = null,
): array {
    $agentId = (string) Str::ulid();
    $bindingId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $graphVersion = hash('sha256', 'dashboard-canonical-'.$artifactId);
    $now = now();
    $artifact = [
        'graph_contract' => [
            'version' => 'hades.graph_artifact.v1',
            'extractor' => ['name' => 'hades-native-php', 'version' => '1', 'mode' => 'native', 'quality' => 'full', 'fallback_reason' => null],
            'coverage' => ['languages' => ['php'], 'files_total' => 1, 'files_analyzed' => 1, 'files_failed' => 0],
            'source' => ['branch' => 'main', 'head_commit' => str_repeat('a', 40)],
        ],
        'nodes' => $nodes ?? [[
            'id' => 'method:DashboardApiReader::graph',
            'labels' => ['Method'],
            'properties' => $nodeProperties ?? ['name' => 'graph', 'path' => 'app/Dashboard/DashboardApiReader.php'],
        ]],
        'relationships' => $relationships ?? [],
    ];
    $json = json_encode($artifact, JSON_THROW_ON_ERROR);

    DB::table('hades_agents')->insert([
        'id' => $agentId, 'project_id' => $projectId, 'external_agent_id' => 'dashboard-'.$agentId,
        'label' => 'Dashboard test agent', 'platform' => 'linux', 'version' => 'test',
        'declared_capabilities' => '[]', 'effective_capabilities' => '[]', 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hades_workspace_bindings')->insert([
        'id' => $bindingId, 'project_id' => $projectId, 'hades_agent_id' => $agentId,
        'external_agent_id' => 'dashboard-'.$agentId, 'workspace_fingerprint' => hash('sha256', $bindingId),
        'display_path' => '/srv/private/project', 'head_commit' => str_repeat('a', 40), 'status' => 'linked',
        'linked_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hades_agent_artifacts')->insert([
        'id' => $artifactId, 'project_id' => $projectId, 'hades_agent_id' => $agentId,
        'workspace_binding_id' => $bindingId, 'schema' => 'hades.php_graph.v1', 'artifact' => $json,
        'sha256' => hash('sha256', $json), 'truncated' => false, 'redactions' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('canonical_graph_projections')->insert([
        'id' => (string) Str::ulid(), 'project_id' => $projectId,
        'source_scope_type' => 'workspace_binding', 'source_scope_id' => $bindingId,
        'artifact_type' => 'hades_agent_artifact', 'artifact_id' => $artifactId,
        'graph_version' => $graphVersion, 'checksum' => hash('sha256', $json),
        'active_graph_version' => $graphVersion,
        'head_commit' => str_repeat('a', 40), 'quality' => 'full', 'status' => 'ready',
        'node_count' => count($artifact['nodes']), 'relationship_count' => count($artifact['relationships']), 'projected_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    return ['agent_id' => $agentId, 'binding_id' => $bindingId, 'graph_version' => $graphVersion];
}

function createDashboardCanonicalBinding(string $projectId, string $agentId): string
{
    $bindingId = (string) Str::ulid();
    $now = now();
    DB::table('hades_workspace_bindings')->insert([
        'id' => $bindingId,
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'external_agent_id' => 'dashboard-'.$agentId,
        'workspace_fingerprint' => hash('sha256', $bindingId),
        'display_path' => '/srv/private/empty-scope',
        'head_commit' => str_repeat('c', 40),
        'status' => 'linked',
        'linked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $bindingId;
}

/** @return list<string> */
function dashboardGraphJsonStrings(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }
    if (! is_array($value)) {
        return [];
    }

    $strings = [];
    foreach ($value as $child) {
        array_push($strings, ...dashboardGraphJsonStrings($child));
    }

    return $strings;
}

function dashboardGraphLooksLikeLocalPath(string $value): bool
{
    $trimmed = trim($value);

    return str_starts_with(strtolower($trimmed), 'file://')
        || str_starts_with($trimmed, '/')
        || preg_match('/^[a-z]:[\\\\\/]/i', $trimmed) === 1
        || str_starts_with($trimmed, '\\\\');
}

/** @param list<string> $unsafeIdentifiers */
function assertDashboardGraphResponseHasNoLocalPaths(array $response, array $unsafeIdentifiers): void
{
    $strings = collect(dashboardGraphJsonStrings($response));

    foreach ($unsafeIdentifiers as $unsafeIdentifier) {
        expect($strings->filter(
            fn (string $publicValue): bool => str_contains($publicValue, $unsafeIdentifier),
        ))->toBeEmpty();
    }

    expect($strings->filter(fn (string $value): bool => preg_match(
        '~(?:file:/{1,3}|(?:node|path|method|class):(?:/|[a-z]:[\\\\/]|\\\\\\\\)|[\\[(=,|]/{1,2})(?:home|users|srv|var|tmp|opt|workspace|usr)(?:[\\\\/]|$)~i',
        $value,
    ) === 1))->toBeEmpty();
}

/** @return array{unsafe: list<string>, safe: list<string>, nodes: list<array<string, mixed>>, relationships: list<array<string, string>>} */
function dashboardGraphPathFixture(): array
{
    $unsafeIdentifiers = [
        'file:/home/private/FileUri.php',
        'node:/home/private/Node.php',
        'path:/var/private/Path.php',
        'method:C:\\Users\\private\\Method.php',
        'class:\\\\server\\private\\Unc.php',
        'symbol:/etc/passwd',
        'edge:/root/.ssh/id_ed25519',
        'method:/mnt/private/Method.php',
        'id:/private/var/private.sqlite',
        'arbitrary-semantic-prefix:/Users/private/Secrets.php',
        'symbol:edge:method:/etc/private/Nested.php',
        'GET /var/log/private.log',
        'POST [/tmp/private/VerbBracket.php]',
        'owner={/home/private/Brace.php}',
        'custom:C:\\Users\\private\\ArbitraryDrive.php',
        'relation:\\\\server\\private\\ArbitraryUnc.php',
        'prefix[/opt/private/Bracket.php]',
        'prefix(/tmp/private/Paren.php)',
        'prefix=/workspace/private/Equals.php',
        'prefix,/usr/local/private/Comma.php',
        'prefix|/srv/private/Pipe.php',
        'symbol@/etc/passwd',
        'symbol#/root/secret',
        'custom@C:\\Users\\private\\AtDrive.php',
        'edge@\\\\server\\private\\AtUnc.php',
    ];
    $safeIdentifiers = [
        'https://example.com/home/private/File.php',
        'http://localhost/api/dashboard',
        '/api/orders',
        'GET /api/orders',
        '/v1/users',
        'POST /v23/orders/123',
        'route:/api/dashboard/runs',
        'symbol:/api/orders',
        'id:/v2/users',
        '/api/etc/passwd',
        '/homebrew/bin/brew',
        'method:App\\Dashboard\\Controller::show',
        'class:App/Dashboard/Controller',
        'node:module-name',
        'urn:example:node',
    ];
    $nodes = [];
    foreach ([...$unsafeIdentifiers, ...$safeIdentifiers] as $identifier) {
        $nodes[] = [
            'id' => $identifier,
            'labels' => ['Method'],
            'properties' => ['name' => $identifier],
        ];
    }
    $relationships = [];
    foreach (array_keys($nodes) as $index) {
        if ($index === count($nodes) - 1) {
            break;
        }
        $relationships[] = [
            'id' => $unsafeIdentifiers[$index % count($unsafeIdentifiers)],
            'source_id' => $nodes[$index]['id'],
            'target_id' => $nodes[$index + 1]['id'],
            'type' => 'calls',
        ];
    }

    return [
        'unsafe' => $unsafeIdentifiers,
        'safe' => $safeIdentifiers,
        'nodes' => $nodes,
        'relationships' => $relationships,
    ];
}

function assertDashboardGraphIdentityInvariants(array $response): void
{
    $nodeIds = collect($response['nodes'])->pluck('id');
    $edgeIds = collect($response['edges'])->pluck('id');

    expect($nodeIds->unique()->count())->toBe($nodeIds->count())
        ->and($edgeIds->unique()->count())->toBe($edgeIds->count())
        ->and(collect($response['edges'])->every(
            fn (array $edge): bool => $nodeIds->contains($edge['from']) && $nodeIds->contains($edge['to']),
        ))->toBeTrue();
}

/** @return array<string, string> */
function dashboardGraphSemanticNodeIdMap(array $response): array
{
    $map = [];
    foreach ($response['nodes'] as $node) {
        $map[(string) $node['label']] = (string) $node['id'];
    }
    ksort($map);

    return $map;
}

/** @return array<string, string> */
function dashboardGraphSemanticEdgeIdMap(array $response): array
{
    $nodeLabels = [];
    foreach ($response['nodes'] as $node) {
        $nodeLabels[(string) $node['id']] = (string) $node['label'];
    }

    $map = [];
    foreach ($response['edges'] as $edge) {
        $semanticKey = implode("\0", [
            $nodeLabels[(string) $edge['from']],
            $nodeLabels[(string) $edge['to']],
            (string) $edge['kind'],
        ]);
        $map[$semanticKey] = (string) $edge['id'];
    }
    ksort($map);

    return $map;
}
