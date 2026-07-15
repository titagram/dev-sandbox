<?php

use App\Services\Graph\CanonicalGraphQueryService;
use App\Services\Graph\DashboardGraphExplorerCursor;
use App\Services\Graph\DashboardGraphExplorerService;
use App\Services\Graph\DashboardGraphPublicHandle;
use App\Services\Neo4j\Neo4jClient;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.key' => 'unit-test-app-key']);
    $this->seed(DevBoardSeeder::class);
});

it('resolves a detail node with one direct indexed project-scope-version-handle lookup', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $response = $fixture['service']->detail(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        10,
    );

    $lookup = collect($fixture['client']->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'MATCH (node:CanonicalGraphNode'),
    );

    expect($response['found'])->toBeTrue()
        ->and($lookup)->not->toBeNull()
        ->and($lookup['cypher'])
        ->toContain('project_id: $project_id')
        ->toContain('source_scope_type: $source_scope_type')
        ->toContain('source_scope_id: $source_scope_id')
        ->toContain('graph_version: $active_graph_version')
        ->toContain('public_handle: $public_handle')
        ->toContain('LIMIT 1')
        ->and($lookup['params'])->toMatchArray([
            'project_id' => $fixture['project_id'],
            'source_scope_type' => 'workspace_binding',
            'source_scope_id' => $fixture['scope_id'],
            'active_graph_version' => $fixture['active_graph_version'],
            'public_handle' => $fixture['handle'],
            'public_handle_key_version' => 'gh1',
            'public_handle_key_fingerprint' => hash_hmac('sha256', 'hades.graph.handle.v1', 'unit-test-app-key'),
        ]);
});

it('uses the safe public name when Hades nodes do not provide a label', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['client']->directNodeUsesNameOnly = true;
    $fixture['client']->neighborhoodRows = [[
        'source_id' => 'method:InvoiceService::charge',
        'node' => [
            'external_id' => 'method:NamedDependency',
            'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                $fixture['project_id'],
                'workspace_binding',
                $fixture['scope_id'],
                $fixture['active_graph_version'],
                'method:NamedDependency',
            ),
            'kind' => 'method',
            'public_search_name' => 'NamedDependency',
        ],
        'labels' => ['Method'],
        'edge' => [
            'edge_json' => json_encode([
                'external_id' => 'edge-named-dependency',
                'type' => 'CALLS_METHOD',
                'source_id' => 'method:InvoiceService::charge',
                'target_id' => 'method:NamedDependency',
            ], JSON_THROW_ON_ERROR),
        ],
    ]];

    $detail = $fixture['service']->detail(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'],
    );
    $neighborhood = $fixture['service']->neighborhood(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 'out', 1, 10,
    );

    expect($detail['node']['label'])->toBe('InvoiceService::charge')
        ->and($neighborhood['items'][0]['label'])->toBe('NamedDependency');
});

it('keeps the newest ready winner when a newer queued candidate has no active version', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    DB::table('canonical_graph_projections')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $fixture['project_id'],
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => $fixture['scope_id'],
        'artifact_type' => 'hades_agent_artifact_candidate',
        'artifact_id' => $fixture['artifact_id'],
        'graph_version' => 'candidate-v3',
        'active_graph_version' => null,
        'checksum' => hash('sha256', 'queued-candidate'),
        'head_commit' => str_repeat('b', 40),
        'quality' => 'partial',
        'status' => 'queued',
        'node_count' => 0,
        'relationship_count' => 0,
        'projected_at' => now()->addMinute(),
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);

    $overview = $fixture['service']->overview($fixture['project_id'], 'workspace_binding', $fixture['scope_id']);
    $search = $fixture['service']->search($fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Invoice Service', 2);
    $detail = $fixture['service']->detail($fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle']);
    $commands = collect($fixture['client']->commands);

    expect($overview['found'])->toBeTrue()
        ->and($search['found'])->toBeTrue()
        ->and($detail['found'])->toBeTrue()
        ->and($commands->filter(fn (array $command): bool => str_contains($command['cypher'], 'db.index.fulltext.queryNodes'))
            ->last()['params']['active_graph_version'])->toBe($fixture['active_graph_version'])
        ->and($commands->filter(fn (array $command): bool => str_contains($command['cypher'], 'OPTIONAL MATCH (node:CanonicalGraphNode'))
            ->last()['params']['active_graph_version'])->toBe($fixture['active_graph_version']);
});

it('resolves detail key state and node existence in one combined indexed lookup', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $response = $fixture['service']->detail(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
    );
    $lookups = collect($fixture['client']->commands)
        ->filter(fn (array $command): bool => str_contains($command['cypher'], 'MATCH (node:CanonicalGraphNode'))
        ->values();
    $keyOnly = collect($fixture['client']->commands)
        ->filter(fn (array $command): bool => str_contains($command['cypher'], 'RETURN version.public_handle_key_version AS public_handle_key_version'))
        ->values();

    expect($response['found'])->toBeTrue()
        ->and($lookups)->toHaveCount(1)
        ->and($keyOnly)->toHaveCount(0)
        ->and($lookups[0]['cypher'])
        ->toContain('OPTIONAL MATCH (node:CanonicalGraphNode')
        ->toContain('version.public_handle_key_version AS version_project_key')
        ->toContain('version.public_handle_key_fingerprint AS version_source_fingerprint')
        ->not->toContain('version.public_handle_key_version = $public_handle_key_version');
});

it('materializes Laudis result lists and maps across explorer reads', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $client = new class($fixture['client']) implements Neo4jClient
    {
        public function __construct(private readonly Neo4jClient $delegate) {}

        public function run(string $cypher, array $parameters = []): mixed
        {
            return $this->cypherValue($this->delegate->run($cypher, $parameters));
        }

        private function cypherValue(mixed $value): mixed
        {
            if (! is_array($value)) {
                return $value;
            }

            $mapped = [];
            foreach ($value as $key => $child) {
                $mapped[$key] = $this->cypherValue($child);
            }

            return array_is_list($value)
                ? new CypherList($mapped)
                : new CypherMap($mapped);
        }
    };
    $service = new DashboardGraphExplorerService(new CanonicalGraphQueryService($client), $client);

    $detail = $service->detail(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
    );
    $overview = $service->overview(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
    );
    $search = $service->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        2,
    );
    $impact = $service->impact(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        10,
    );

    expect($detail['found'])->toBeTrue()
        ->and($overview['found'])->toBeTrue()
        ->and($search['items'])->toHaveCount(2)
        ->and($impact['items'])->toHaveCount(1);
});

it('guards direct handle resolution with the current key identity in the same indexed lookup', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $response = $fixture['service']->detail(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
    );
    $lookup = collect($fixture['client']->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'MATCH (node:CanonicalGraphNode'),
    );

    expect($response['found'])->toBeTrue()
        ->and($lookup['cypher'])
        ->toContain('MATCH (version:CanonicalGraphVersion')
        ->toContain('version.public_handle_key_version AS version_project_key')
        ->toContain('version.public_handle_key_fingerprint AS version_source_fingerprint')
        ->toContain('OPTIONAL MATCH (node:CanonicalGraphNode')
        ->and($lookup['params'])
        ->toMatchArray([
            'public_handle_key_version' => 'gh1',
            'public_handle_key_fingerprint' => hash_hmac('sha256', 'hades.graph.handle.v1', 'unit-test-app-key'),
        ]);
});

it('does not resolve an old-key handle after rebuilt publication rotates APP_KEY', function (): void {
    $keyA = 'rotation-key-a';
    $keyB = 'rotation-key-b';
    config(['app.key' => $keyA]);
    $fixture = dashboardGraphExplorerFixture();
    $oldHandle = $fixture['handle'];
    config(['app.key' => $keyB]);
    $fixture['client']->storedKeyFingerprint = hash_hmac('sha256', 'hades.graph.handle.v1', $keyB);
    $fixture['client']->storedHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'method:InvoiceService::charge',
    );

    $response = $fixture['service']->detail(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $oldHandle,
    );

    expect($response)->toMatchArray(['found' => false, 'reason' => 'node_not_found'])
        ->and($fixture['client']->commands[array_key_last($fixture['client']->commands)]['params'])
        ->toMatchArray([
            'active_graph_version' => $fixture['active_graph_version'],
            'public_handle_key_fingerprint' => hash_hmac('sha256', 'hades.graph.handle.v1', $keyB),
        ]);
});

it('does not query Neo4j for malformed handles and never resolves tampered or cross-context handles', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $initialCommandCount = count($fixture['client']->commands);

    $malformed = $fixture['service']->detail(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'gh1_not-a-handle',
    );
    $tamperedCharacter = $fixture['handle'][5] === 'A' ? 'B' : 'A';
    $tamperedHandle = substr_replace($fixture['handle'], $tamperedCharacter, 5, 1);
    $tampered = $fixture['service']->detail(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $tamperedHandle,
    );
    $crossContextHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        (string) Str::ulid(),
        $fixture['active_graph_version'],
        'method:InvoiceService::charge',
    );
    $crossContext = $fixture['service']->detail(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $crossContextHandle,
    );

    expect($malformed)->toMatchArray(['found' => false, 'reason' => 'node_not_found'])
        ->and($tampered)->toMatchArray(['found' => false, 'reason' => 'node_not_found'])
        ->and($crossContext)->toMatchArray(['found' => false, 'reason' => 'node_not_found'])
        ->and(count($fixture['client']->commands))->toBe($initialCommandCount + 2);
});

it('rejects a cursor from another project as invalid_cursor', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $cursor = (new DashboardGraphExplorerCursor)->encode(
        'another-project',
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'search',
        'invoice service',
        '0.900|'.$fixture['handle'],
    );

    expect(fn () => $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'invoice service',
        10,
        $cursor,
    ))->toThrow(InvalidArgumentException::class, 'invalid_cursor');
});

it('rejects every remaining cursor context mismatch as invalid_cursor', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $cursorFactory = new DashboardGraphExplorerCursor;
    $cursors = [
        $cursorFactory->encode(
            $fixture['project_id'],
            'repository',
            $fixture['scope_id'],
            $fixture['active_graph_version'],
            'search',
            'invoice service',
            '0.900|'.$fixture['handle'],
        ),
        $cursorFactory->encode(
            $fixture['project_id'],
            'workspace_binding',
            $fixture['scope_id'],
            'origin-v1',
            'search',
            'invoice service',
            '0.900|'.$fixture['handle'],
        ),
        $cursorFactory->encode(
            $fixture['project_id'],
            'workspace_binding',
            $fixture['scope_id'],
            $fixture['active_graph_version'],
            'detail',
            'invoice service',
            '0.900|'.$fixture['handle'],
        ),
        $cursorFactory->encode(
            $fixture['project_id'],
            'workspace_binding',
            $fixture['scope_id'],
            $fixture['active_graph_version'],
            'search',
            'other query',
            '0.900|'.$fixture['handle'],
        ),
    ];

    foreach ($cursors as $cursor) {
        expect(fn () => $fixture['service']->search(
            $fixture['project_id'],
            'workspace_binding',
            $fixture['scope_id'],
            'invoice service',
            10,
            $cursor,
        ))->toThrow(InvalidArgumentException::class, 'invalid_cursor');
    }

    expect($fixture['client']->commands)->toBe([]);
});

it('searches sanitized fields with stable limit-plus-one pagination', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $response = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        2,
    );
    $search = collect($fixture['client']->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'db.index.fulltext.queryNodes'),
    );

    expect($response['found'])->toBeTrue()
        ->and($response['returned'])->toBe(2)
        ->and($response['has_more'])->toBeTrue()
        ->and($response['next_cursor'])->not->toBeNull()
        ->and($response['items'])->toHaveCount(2)
        ->and($response['items'][0]['handle'])->toBeLessThan($response['items'][1]['handle'])
        ->and($search)->not->toBeNull()
        ->and($search['cypher'])->toContain(
            'CALL db.index.fulltext.queryNodes(\'canonical_node_search_v2\', $lucene_query)',
        )
        ->not->toContain('active-v2')
        ->toContain('WHERE node.graph_version = $active_graph_version')
        ->not->toContain('CONTAINS')
        ->not->toContain('external_id')
        ->not->toContain('node.path')
        ->and($search['params']['fetch_limit'])->toBe(3)
        ->and(json_encode($response, JSON_THROW_ON_ERROR))
        ->not->toContain('node:internal')
        ->not->toContain('/srv/private');
});

it('searches normalized route uri tokens through the versioned canonical index', function (): void {
    $fixture = dashboardGraphExplorerFixture();

    $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        '/generale/soggetti-attivi/',
        10,
    );
    $search = collect($fixture['client']->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'db.index.fulltext.queryNodes'),
    );

    expect($search['cypher'])
        ->toContain("db.index.fulltext.queryNodes('canonical_node_search_v2', \$lucene_query)")
        ->toContain('node.public_search_name_normalized = $normalized_query')
        ->and($search['params']['lucene_query'])
        ->toContain('public_search_terms:soggetti*')
        ->toContain('public_search_terms:attivi*')
        ->and($search['params']['normalized_query'])->toBe('/generale/soggetti-attivi/');
});

it('ranks exact route and symbol names before unrelated fuzzy results', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $routeHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'route:/generale/soggetti-attivi/',
    );
    $workerHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'route:contact_flock_roles_worker',
    );
    $fixture['client']->searchRows = [
        [
            'node' => [
                'public_handle' => $workerHandle,
                'kind' => 'route',
                'public_search_name' => 'contact_flock_roles_worker',
                'public_search_path_normalized' => '/generale/other/',
                'public_search_label' => 'contact_flock_roles_worker',
            ],
            'labels' => ['Route'],
            'score' => 8.0,
        ],
        [
            'node' => [
                'public_handle' => $routeHandle,
                'kind' => 'route',
                'public_search_name' => 'contact_flock_roles_soggetti_attivi',
                'public_search_path_normalized' => '/generale/soggetti-attivi/',
                'public_source_file' => 'routes/web.php',
                'public_line_start' => 42,
                'public_namespace' => 'App\\Routing',
                'public_search_label' => '/generale/soggetti-attivi/',
            ],
            'labels' => ['Route'],
            'score' => 0.1,
        ],
    ];

    $response = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        '/generale/soggetti-attivi/',
        10,
    );

    expect($response['items'][0])->toMatchArray([
        'handle' => $routeHandle,
        'match_type' => 'exact_route_path',
        'source_file' => 'routes/web.php',
        'line_start' => 42,
        'namespace' => 'App\\Routing',
    ])->and($response['items'][0]['match_reason'])->toContain('Exact route')
        ->and($response['items'][1]['handle'])->toBe($workerHandle);
});

it('does not return a fuzzy answer when capacity may have omitted an exact symbol', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    DB::table('canonical_graph_projections')
        ->where('id', $fixture['projection_id'])
        ->update([
            'quality' => 'partial',
            'coverage' => json_encode([
                'languages' => ['php'],
                'files_total' => 10,
                'files_analyzed' => 10,
                'files_failed' => 0,
                'nodes_capacity_omitted' => 3,
            ], JSON_THROW_ON_ERROR),
        ]);
    $fixture['client']->searchRows = [[
        'node' => [
            'public_handle' => $fixture['handle'],
            'kind' => 'class',
            'public_search_name' => 'AdminControllerBulkDeleteBehaviorTest',
            'public_search_label' => 'AdminControllerBulkDeleteBehaviorTest',
        ],
        'labels' => ['Class'],
        'score' => 9.0,
    ]];

    $response = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'AdminControllerBulkDeleteBehavior',
        10,
    );

    expect($response)->toMatchArray([
        'found' => true,
        'reason' => 'exact_match_not_indexed_capacity',
        'items' => [],
        'completeness' => 'partial',
    ]);
});

it('does not turn an absent exact-looking symbol into a fuzzy answer', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['client']->searchRows = [[
        'node' => [
            'public_handle' => $fixture['handle'],
            'kind' => 'class',
            'public_search_name' => 'AdminControllerBulkDeleteBehaviorTest',
            'public_search_label' => 'AdminControllerBulkDeleteBehaviorTest',
        ],
        'labels' => ['Class'],
        'score' => 9.0,
    ]];

    expect($fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'AdminControllerBulkDeleteBehavior',
        10,
    ))->toMatchArray([
        'found' => true,
        'reason' => 'exact_match_not_found',
        'items' => [],
        'completeness' => 'verified_none',
    ]);
});

it('reports projection completeness on direct detail cards', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    DB::table('canonical_graph_projections')->where('id', $fixture['projection_id'])->update(['quality' => 'partial']);

    expect($fixture['service']->detail(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'],
    ))->toMatchArray(['completeness' => 'partial']);
});

it('ranks an exact class above its similarly named test class', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $exactHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'class:AdminControllerBulkDeleteBehavior',
    );
    $testHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'test:AdminControllerBulkDeleteBehaviorTest',
    );
    $fixture['client']->searchRows = [
        [
            'node' => [
                'public_handle' => $testHandle,
                'kind' => 'test',
                'public_search_name' => 'AdminControllerBulkDeleteBehaviorTest',
                'public_search_name_normalized' => 'admincontrollerbulkdeletebehaviortest',
                'public_search_label' => 'AdminControllerBulkDeleteBehaviorTest',
            ],
            'labels' => ['Test'],
            'score' => 9.0,
        ],
        [
            'node' => [
                'public_handle' => $exactHandle,
                'kind' => 'class',
                'public_search_name' => 'AdminControllerBulkDeleteBehavior',
                'public_search_name_normalized' => 'admincontrollerbulkdeletebehavior',
                'public_search_label' => 'AdminControllerBulkDeleteBehavior',
            ],
            'labels' => ['Class'],
            'score' => 0.1,
        ],
    ];

    $response = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'AdminControllerBulkDeleteBehavior',
        10,
    );

    expect($response['items'][0])->toMatchArray([
        'handle' => $exactHandle,
        'match_type' => 'exact_symbol_name',
    ])->and($response['items'][1]['handle'])->toBe($testHandle);
});

it('bounds search limits and preserves cursor scores without lossy rounding', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['client']->searchScores = [0.123456789, 0.1, 0.05];

    $precise = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        1,
    );
    $bounded = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        101,
    );
    $searchCommands = collect($fixture['client']->commands)
        ->filter(fn (array $command): bool => str_contains($command['cypher'], 'db.index.fulltext.queryNodes'))
        ->values();
    $sortKey = (new DashboardGraphExplorerCursor)->decode($precise['next_cursor'])['sort_key'];

    expect($sortKey)->toStartWith('0.123456789|')
        ->and($bounded['limit'])->toBe(100)
        ->and($bounded['has_more'])->toBeFalse()
        ->and($searchCommands[0]['params']['fetch_limit'])->toBe(2)
        ->and($searchCommands[1]['params']['fetch_limit'])->toBe(101);
});

it('guards search results with the active scope, version, and current handle key', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        2,
    );
    $search = collect($fixture['client']->commands)->last(
        fn (array $command): bool => str_contains($command['cypher'], 'db.index.fulltext.queryNodes'),
    );

    expect($search['cypher'])
        ->toContain('MATCH (version:CanonicalGraphVersion')
        ->toContain('project_id: $project_id')
        ->toContain('source_scope_type: $source_scope_type')
        ->toContain('source_scope_id: $source_scope_id')
        ->toContain('graph_version: $active_graph_version')
        ->toContain('version.public_handle_key_version AS version_project_key')
        ->toContain('version.public_handle_key_fingerprint AS version_source_fingerprint')
        ->not->toContain('current = true')
        ->and($search['params'])->not->toHaveKey('public_handle_key_version')
        ->and($search['params'])->not->toHaveKey('public_handle_key_fingerprint');
});

it('checks search key metadata in its single fulltext query without a key preflight', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        2,
    );
    $fulltext = collect($fixture['client']->commands)
        ->filter(fn (array $command): bool => str_contains($command['cypher'], 'db.index.fulltext.queryNodes'))
        ->values();
    $keyOnly = collect($fixture['client']->commands)
        ->filter(fn (array $command): bool => str_contains($command['cypher'], 'RETURN version.public_handle_key_version AS public_handle_key_version'))
        ->values();

    expect($fulltext)->toHaveCount(1)
        ->and($keyOnly)->toHaveCount(0)
        ->and($fulltext[0]['cypher'])
        ->toContain('version.public_handle_key_version AS version_project_key')
        ->toContain('version.public_handle_key_fingerprint AS version_source_fingerprint');
});

it('applies the decoded score and handle boundary to the next search page', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $pageOne = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        2,
    );
    $pageTwo = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        2,
        $pageOne['next_cursor'],
    );
    $searchCommands = collect($fixture['client']->commands)
        ->filter(fn (array $command): bool => str_contains($command['cypher'], 'db.index.fulltext.queryNodes'))
        ->values();
    [$cursorScore, $cursorHandle] = explode('|', (new DashboardGraphExplorerCursor)->decode($pageOne['next_cursor'])['sort_key'], 2);

    expect(array_intersect(
        array_column($pageOne['items'], 'handle'),
        array_column($pageTwo['items'], 'handle'),
    ))->toBe([])
        ->and($searchCommands)->toHaveCount(2)
        ->and($searchCommands[1]['cypher'])
        ->toContain('(score < $cursor_score OR (score = $cursor_score AND node.public_handle > $cursor_handle))')
        ->and($searchCommands[1]['params']['cursor_score'])->toBe((float) $cursorScore)
        ->and($searchCommands[1]['params']['cursor_handle'])->toBe($cursorHandle);
});

it('binds an escaped Lucene query instead of interpolating apostrophes or operators', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $rawQuery = "Invoice' +(Service) && ||";
    $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $rawQuery,
        2,
    );
    $search = collect($fixture['client']->commands)->last(
        fn (array $command): bool => str_contains($command['cypher'], 'db.index.fulltext.queryNodes'),
    );

    expect($search['cypher'])
        ->toContain('CALL db.index.fulltext.queryNodes(\'canonical_node_search_v2\', $lucene_query)')
        ->not->toContain($rawQuery)
        ->not->toContain('active-v2')
        ->and($search['params']['lucene_query'])
        ->toContain('public_search_terms:invoice*')
        ->toContain('public_search_terms:service*')
        ->not->toContain("'")
        ->not->toContain('+')
        ->not->toContain('&')
        ->not->toContain('|');
});

it('rejects empty or invalid normalized searches before querying the index', function (): void {
    $fixture = dashboardGraphExplorerFixture();

    expect(fn () => $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        " \t\n ",
    ))->toThrow(InvalidArgumentException::class, 'invalid_query');
    expect(fn () => $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        "\xFF",
    ))->toThrow(InvalidArgumentException::class, 'invalid_query');
    expect(fn () => $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        str_repeat('a', 161),
    ))->toThrow(InvalidArgumentException::class, 'invalid_query');
    expect($fixture['client']->commands)->toBe([]);
});

it('does not expose technical legacy search labels through public mapping', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $legacyHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'method:Legacy',
    );
    $fixture['client']->searchRows = [[
        'node' => [
            'external_id' => 'method:Legacy',
            'public_handle' => $legacyHandle,
            'kind' => 'method',
            'public_search_name' => 'node-legacy-123',
            'public_search_label' => 'hades-public-v1-label-legacy',
        ],
        'labels' => ['Method'],
        'score' => 1.0,
    ]];

    expect($fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Legacy',
        10,
    )['items'])->toBe([]);
});

it('returns safe current projection metadata for an overview', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $response = $fixture['service']->overview(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
    );

    expect($response['found'])->toBeTrue()
        ->and($response['projection'])->toMatchArray([
            'status' => 'ready',
            'quality' => 'full',
            'active_graph_version' => $fixture['active_graph_version'],
            'node_count' => 3,
            'relationship_count' => 2,
        ])
        ->and(json_encode($response, JSON_THROW_ON_ERROR))
        ->not->toContain($fixture['projection_id'])
        ->not->toContain($fixture['artifact_id'])
        ->not->toContain('/srv/private')
        ->not->toContain('hades_agent_artifact');
});

it('reports rebuild required when overview metadata has a rotated handle key', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['client']->storedKeyFingerprint = hash_hmac('sha256', 'hades.graph.handle.v1', 'previous-app-key');

    expect($fixture['service']->overview(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
    ))->toMatchArray([
        'found' => false,
        'reason' => 'graph_projection_rebuild_required',
    ]);
});

it('distinguishes unavailable and stale projections before node resolution', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    DB::table('canonical_graph_projections')->where('id', $fixture['projection_id'])->delete();

    foreach ([
        fn (): array => $fixture['service']->neighborhood(
            $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 'any', 2, 10,
        ),
        fn (): array => $fixture['service']->path(
            $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], $fixture['handle'], 2, 10,
        ),
        fn (): array => $fixture['service']->impact(
            $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10,
        ),
    ] as $read) {
        expect($read())->toMatchArray([
            'found' => false,
            'reason' => 'graph_projection_not_ready',
        ]);
    }

    $fixture = dashboardGraphExplorerFixture();
    DB::table('canonical_graph_projections')
        ->where('id', $fixture['projection_id'])
        ->update(['status' => 'stale']);

    foreach ([
        fn (): array => $fixture['service']->neighborhood(
            $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 'any', 2, 10,
        ),
        fn (): array => $fixture['service']->path(
            $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], $fixture['handle'], 2, 10,
        ),
        fn (): array => $fixture['service']->impact(
            $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10,
        ),
    ] as $read) {
        expect($read())->toMatchArray([
            'found' => false,
            'reason' => 'graph_projection_rebuild_required',
        ]);
    }
});

it('prioritizes stale or failed projection states over queued candidates without a ready winner', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    DB::table('canonical_graph_projections')->where('id', $fixture['projection_id'])->delete();
    $base = [
        'project_id' => $fixture['project_id'],
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => $fixture['scope_id'],
        'artifact_type' => 'candidate_projection',
        'checksum' => hash('sha256', 'mixed-candidates'),
        'head_commit' => str_repeat('c', 40),
        'quality' => 'partial',
        'node_count' => 0,
        'relationship_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    DB::table('canonical_graph_projections')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'artifact_id' => (string) Str::ulid(),
        'graph_version' => 'stale-v1',
        'active_graph_version' => 'stale-active-v1',
        'status' => 'stale',
        'projected_at' => now()->addSeconds(1),
    ]);
    DB::table('canonical_graph_projections')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'artifact_id' => (string) Str::ulid(),
        'graph_version' => 'queued-v2',
        'active_graph_version' => null,
        'status' => 'queued',
        'projected_at' => now()->addSeconds(2),
    ]);

    expect($fixture['service']->overview(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'],
    ))->toMatchArray([
        'found' => false,
        'reason' => 'graph_projection_rebuild_required',
    ]);
});

it('returns rebuild required for search and detail when the current projection key is stale', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['client']->storedKeyFingerprint = hash_hmac('sha256', 'hades.graph.handle.v1', 'previous-app-key');

    expect($fixture['service']->search(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Invoice Service', 10,
    ))->toMatchArray([
        'found' => false,
        'reason' => 'graph_projection_rebuild_required',
    ])->and($fixture['service']->detail(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10,
    ))->toMatchArray([
        'found' => false,
        'reason' => 'graph_projection_rebuild_required',
    ]);
});

it('does not let invalid public rows consume a bounded search page', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $makeNode = static function (string $externalId, string $label) use ($fixture): array {
        return [
            'external_id' => $externalId,
            'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                $fixture['project_id'],
                'workspace_binding',
                $fixture['scope_id'],
                $fixture['active_graph_version'],
                $externalId,
            ),
            'kind' => 'method',
            'public_search_name' => $label,
            'public_search_label' => $label,
        ];
    };
    $invalid = ['node' => $makeNode('method:technical', 'hades-public-v1-technical'), 'labels' => ['Method'], 'score' => 1.0];
    $firstValid = ['node' => $makeNode('method:first-valid', 'First Valid'), 'labels' => ['Method'], 'score' => 0.9];
    $secondValid = ['node' => $makeNode('method:second-valid', 'Second Valid'), 'labels' => ['Method'], 'score' => 0.8];
    $fixture['client']->searchRowBatches = [
        [$invalid, $firstValid],
        [$secondValid],
        [$secondValid],
    ];
    $fixture['client']->searchRows = [
        ['node' => $makeNode('method:technical', 'hades-public-v1-technical'), 'labels' => ['Method'], 'score' => 1.0],
        ['node' => $makeNode('method:first-valid', 'First Valid'), 'labels' => ['Method'], 'score' => 0.9],
    ];

    $page = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        1,
    );
    $nextPage = $fixture['service']->search(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        'Invoice Service',
        1,
        $page['next_cursor'],
    );

    expect($page['returned'])->toBe(1)
        ->and($page['items'][0]['label'])->toBe('First Valid')
        ->and($page['has_more'])->toBeTrue()
        ->and($page['next_cursor'])->not->toBeNull()
        ->and($nextPage['items'])->toHaveCount(1)
        ->and($nextPage['items'][0]['label'])->toBe('Second Valid')
        ->and($nextPage['items'][0]['handle'])->not->toBe($page['items'][0]['handle']);
});

it('maps every explorer node output through a closed public kind vocabulary', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $maliciousKind = '/srv/private/Foo.php';
    $technicalKind = 'hades-public-v1-node-secret';
    $makeHandle = static fn (string $externalId): string => (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        $externalId,
    );

    $fixture['client']->searchRows = [[
        'node' => [
            'external_id' => 'method:search-kind',
            'public_handle' => $makeHandle('method:search-kind'),
            'kind' => $maliciousKind,
            'public_search_label' => 'Search kind',
        ],
        'labels' => ['Method'],
        'score' => 1.0,
    ]];
    $search = $fixture['service']->search(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Search kind', 10,
    );

    $fixture['client']->directNodeKind = $technicalKind;
    $detail = $fixture['service']->detail(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10,
    );

    $fixture['client']->neighborhoodRows = [[
        'source_id' => 'method:InvoiceService::charge',
        'node' => [
            'external_id' => 'method:neighborhood-kind',
            'public_handle' => $makeHandle('method:neighborhood-kind'),
            'kind' => $maliciousKind,
            'public_search_label' => 'Neighborhood kind',
        ],
        'labels' => ['Method'],
    ]];
    $neighborhood = $fixture['service']->neighborhood(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 'out', 2, 10,
    );

    $fixture['client']->pathRows = [[
        'nodes' => [
            ['node' => ['external_id' => 'method:InvoiceService::charge', 'public_handle' => $fixture['handle'], 'kind' => 'method', 'public_search_label' => 'Root'], 'labels' => ['Method']],
            ['node' => ['external_id' => 'method:path-kind', 'public_handle' => $makeHandle('method:path-kind'), 'kind' => $technicalKind, 'public_search_label' => 'Path kind'], 'labels' => ['Method']],
        ],
        'edges' => [],
    ]];
    $path = $fixture['service']->path(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], $fixture['handle'], 2, 10,
    );

    $fixture['client']->impactRows = [[
        'node' => [
            'external_id' => 'method:impact-kind',
            'public_handle' => $makeHandle('method:impact-kind'),
            'kind' => $maliciousKind,
            'public_search_label' => 'Impact kind',
        ],
        'labels' => ['Method'],
        'distance' => 1,
        'edge_types' => ['CALLS_METHOD'],
    ]];
    $impact = $fixture['service']->impact(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10,
    );

    $response = [$search, $detail, $neighborhood, $path, $impact];
    expect($search['items'][0]['kind'])->toBe('unknown')
        ->and($detail['node']['kind'])->toBe('unknown')
        ->and($neighborhood['items'][0]['kind'])->toBe('unknown')
        ->and($path['items'][1]['kind'])->toBe('unknown')
        ->and($impact['items'][0]['kind'])->toBe('unknown')
        ->and(json_encode($response, JSON_THROW_ON_ERROR))
        ->not->toContain($maliciousKind)
        ->not->toContain($technicalKind);
});

it('retains recognized method and service kinds in explorer responses', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['client']->searchRows = [[
        'node' => [
            'external_id' => 'service:known',
            'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], 'service:known',
            ),
            'kind' => 'service',
            'public_search_label' => 'Known service',
        ],
        'labels' => ['Service'],
        'score' => 1.0,
    ]];
    $search = $fixture['service']->search(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Known service', 10,
    );
    $fixture['client']->directNodeKind = 'method';
    $detail = $fixture['service']->detail(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10,
    );

    expect($search['items'][0]['kind'])->toBe('unknown')
        ->and($detail['node']['kind'])->toBe('method');
});

it('maps every exact public kind and rejects legacy aliases in service responses', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $kinds = [
        'method', 'class', 'method_reference', 'external_class', 'table',
        'route', 'trait', 'external_symbol', 'interface', 'file',
    ];

    foreach ($kinds as $kind) {
        $fixture['client']->directNodeKind = $kind;
        expect($fixture['service']->detail(
            $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10,
        )['node']['kind'])->toBe($kind);
    }

    foreach (['service', 'module', 'function', 'model', 'enum', 'http_endpoint'] as $kind) {
        $fixture['client']->directNodeKind = $kind;
        expect($fixture['service']->detail(
            $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10,
        )['node']['kind'])->toBe('unknown');
    }
});

it('proves exact exhaustion at the bounded raw search ceiling', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $makeInvalidRow = static function (int $index) use ($fixture): array {
        $externalId = 'method:ceiling-invalid-'.$index;

        return [
            'node' => [
                'external_id' => $externalId,
                'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                    $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], $externalId,
                ),
                'kind' => 'method',
                'public_search_label' => 'hades-public-v1-invalid',
            ],
            'labels' => ['Method'],
            'score' => 1.0 - ($index / 100000),
        ];
    };
    $fixture['client']->searchRowBatches = [];
    for ($index = 0; $index < 500; $index++) {
        $fixture['client']->searchRowBatches[] = [$makeInvalidRow($index * 2), $makeInvalidRow($index * 2 + 1)];
    }

    $response = $fixture['service']->search(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Invoice Service', 1,
    );

    expect($response['items'])->toBe([])
        ->and($response['has_more'])->toBeFalse()
        ->and($response['next_cursor'])->toBeNull();
});

it('continues past 1000 invalid raw rows with a one-row lookahead', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $makeRow = static function (string $externalId, string $label, float $score) use ($fixture): array {
        return [
            'node' => [
                'external_id' => $externalId,
                'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                    $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], $externalId,
                ),
                'kind' => 'method',
                'public_search_label' => $label,
            ],
            'labels' => ['Method'],
            'score' => $score,
        ];
    };
    $fixture['client']->searchRowBatches = [];
    for ($index = 0; $index < 499; $index++) {
        $fixture['client']->searchRowBatches[] = [
            $makeRow('method:ceiling-invalid-'.$index.'-a', 'hades-public-v1-invalid', 1.0 - (($index * 2) / 100000)),
            $makeRow('method:ceiling-invalid-'.$index.'-b', 'hades-public-v1-invalid', 1.0 - (($index * 2 + 1) / 100000)),
        ];
    }
    $lastInvalidA = $makeRow('method:ceiling-invalid-998', 'hades-public-v1-invalid', 0.001);
    $lastInvalidB = $makeRow('method:ceiling-invalid-999', 'hades-public-v1-invalid', 0.0009);
    $valid = $makeRow('method:beyond-ceiling', 'Beyond ceiling', 0.0008);
    $fixture['client']->searchRowBatches[] = [$lastInvalidA, $lastInvalidB, $valid];
    $fixture['client']->searchRowBatches[] = [$valid];

    $firstPage = $fixture['service']->search(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Invoice Service', 1,
    );
    $secondPage = $fixture['service']->search(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Invoice Service', 1, $firstPage['next_cursor'],
    );

    expect($firstPage['items'])->toBe([])
        ->and($firstPage['has_more'])->toBeTrue()
        ->and($firstPage['next_cursor'])->not->toBeNull()
        ->and($secondPage['items'][0]['label'])->toBe('Beyond ceiling')
        ->and(json_encode([$firstPage, $secondPage], JSON_THROW_ON_ERROR))
        ->not->toContain('method:beyond-ceiling');
});

it('uses the final processed raw boundary when a partial public page is truncated', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $makeRow = static function (string $externalId, string $label, float $score) use ($fixture): array {
        return [
            'node' => [
                'external_id' => $externalId,
                'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                    $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], $externalId,
                ),
                'kind' => 'method',
                'public_search_label' => $label,
            ],
            'labels' => ['Method'],
            'score' => $score,
        ];
    };
    $fixture['client']->searchRowBatches = [];
    for ($index = 0; $index < 499; $index++) {
        $fixture['client']->searchRowBatches[] = [
            $makeRow('method:partial-invalid-'.$index.'-a', 'hades-public-v1-invalid', 1.0 - (($index * 2) / 100000)),
            $makeRow('method:partial-invalid-'.$index.'-b', 'hades-public-v1-invalid', 1.0 - (($index * 2 + 1) / 100000)),
        ];
    }
    $public = $makeRow('method:partial-public', 'Partial public', 0.001);
    $lastProcessedInvalid = $makeRow('method:partial-invalid-final', 'hades-public-v1-invalid', 0.0009);
    $lookahead = $makeRow('method:partial-invalid-lookahead', 'hades-public-v1-invalid', 0.0008);
    $next = $makeRow('method:partial-next', 'Partial next', 0.0007);
    $fixture['client']->searchRowBatches[] = [$public, $lastProcessedInvalid, $lookahead];
    $fixture['client']->searchRowBatches[] = [$next];

    $firstPage = $fixture['service']->search(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Invoice Service', 1,
    );
    $cursor = (new DashboardGraphExplorerCursor)->decode($firstPage['next_cursor']);
    $secondPage = $fixture['service']->search(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], 'Invoice Service', 1, $firstPage['next_cursor'],
    );

    [$cursorScore] = explode('|', $cursor['sort_key'], 2);
    expect($firstPage['items'][0]['label'])->toBe('Partial public')
        ->and((float) $cursorScore)->toBe(0.0009)
        ->and($secondPage['items'][0]['label'])->toBe('Partial next')
        ->and($secondPage['items'][0]['handle'])->not->toBe($firstPage['items'][0]['handle']);
});

it('lists project scopes in stable order with limit-plus-one cursors', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $repositoryId = (string) DB::table('repositories')->where('project_id', $fixture['project_id'])->value('id');
    DB::table('canonical_graph_projections')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $fixture['project_id'],
        'source_scope_type' => 'repository',
        'source_scope_id' => $repositoryId,
        'artifact_type' => 'legacy_artifact',
        'artifact_id' => 'legacy-scope-artifact',
        'graph_version' => 'repository-origin-v1',
        'active_graph_version' => 'repository-active-v2',
        'checksum' => hash('sha256', 'repository-scope'),
        'head_commit' => str_repeat('b', 40),
        'quality' => 'full',
        'status' => 'ready',
        'node_count' => 1,
        'relationship_count' => 0,
        'projected_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pageOne = $fixture['service']->scopes($fixture['project_id'], 1);
    $pageTwo = $fixture['service']->scopes($fixture['project_id'], 1, $pageOne['next_cursor']);

    expect($pageOne['found'])->toBeTrue()
        ->and($pageOne['returned'])->toBe(1)
        ->and($pageOne['has_more'])->toBeTrue()
        ->and($pageOne['items'][0]['source_scope_type'])->toBe('repository')
        ->and($pageTwo['items'][0]['source_scope_type'])->toBe('workspace_binding')
        ->and(array_intersect(
            array_column($pageOne['items'], 'source_scope_id'),
            array_column($pageTwo['items'], 'source_scope_id'),
        ))->toBe([])
        ->and(json_encode([$pageOne, $pageTwo], JSON_THROW_ON_ERROR))
        ->not->toContain('legacy-scope-artifact')
        ->not->toContain('/srv/private');
});

it('selects latest ready scopes and applies cursor boundaries in bounded SQL', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    DB::table('canonical_graph_projections')
        ->where('id', $fixture['projection_id'])
        ->update([
            'active_graph_version' => 'workspace-active-new',
            'projected_at' => now()->addSecond(),
        ]);
    $repositoryId = (string) DB::table('repositories')->where('project_id', $fixture['project_id'])->value('id');
    DB::table('canonical_graph_projections')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $fixture['project_id'],
        'source_scope_type' => 'repository',
        'source_scope_id' => $repositoryId,
        'artifact_type' => 'legacy_artifact',
        'artifact_id' => 'legacy-scope-artifact',
        'graph_version' => 'repository-origin-v1',
        'active_graph_version' => 'repository-active-v2',
        'checksum' => hash('sha256', 'repository-scope'),
        'head_commit' => str_repeat('b', 40),
        'quality' => 'full',
        'status' => 'ready',
        'node_count' => 1,
        'relationship_count' => 0,
        'projected_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'canonical_graph_projections')) {
            $queries[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        }
    });

    $pageOne = $fixture['service']->scopes($fixture['project_id'], 1);
    $pageTwo = $fixture['service']->scopes($fixture['project_id'], 1, $pageOne['next_cursor']);
    $boundedQueries = array_values(array_filter(
        $queries,
        static fn (array $query): bool => str_contains($query['sql'], 'select * from')
            && str_contains($query['sql'], 'limit'),
    ));
    expect($pageOne['items'][0]['source_scope_type'])->toBe('repository')
        ->and($pageTwo['items'][0]['active_graph_version'])->toBe('workspace-active-new')
        ->and($boundedQueries)->toHaveCount(2)
        ->and($boundedQueries[0]['sql'])->toContain('row_number() over')
        ->toContain('limit 2')
        ->and($boundedQueries[1]['bindings'])->toContain('repository')
        ->toContain($pageOne['items'][0]['source_scope_id']);
});

it('maps a bounded canonical neighborhood to public handles and edge families', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $response = $fixture['service']->neighborhood(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        'in',
        2,
        10,
        ['call'],
    );

    expect($response['found'])->toBeTrue()
        ->and($response['items'])->toHaveCount(1)
        ->and($response['items'][0]['handle'])->toStartWith('gh1_')
        ->and($response['edges'])->toHaveCount(1)
        ->and($response['edges'][0]['family'])->toBe('call')
        ->and($response['edges'][0]['edge_type'])->toBe('CALLS_METHOD')
        ->and(json_encode($response, JSON_THROW_ON_ERROR))
        ->not->toContain('method:Caller')
        ->not->toContain('edge-neighbor')
        ->not->toContain('/srv/private');
});

it('keeps the neighborhood root outside the public neighbor limit and filters retained endpoints', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $callerA = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], 'method:CallerA',
    );
    $callerB = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], 'method:CallerB',
    );
    $fixture['client']->neighborhoodRows = [
        [
            'source_id' => 'method:InvoiceService::charge',
            'node' => ['external_id' => 'method:CallerA', 'public_handle' => $callerA, 'kind' => 'method', 'public_search_label' => 'Caller A'],
            'labels' => ['Method'],
            'edge' => ['edge_json' => json_encode(['external_id' => 'edge-a', 'type' => 'CALLS_METHOD', 'source_id' => 'method:InvoiceService::charge', 'target_id' => 'method:CallerA'], JSON_THROW_ON_ERROR)],
        ],
        [
            'source_id' => 'method:InvoiceService::charge',
            'node' => ['external_id' => 'method:CallerB', 'public_handle' => $callerB, 'kind' => 'method', 'public_search_label' => 'Caller B'],
            'labels' => ['Method'],
            'edge' => ['edge_json' => json_encode(['external_id' => 'edge-b', 'type' => 'CALLS_METHOD', 'source_id' => 'method:InvoiceService::charge', 'target_id' => 'method:CallerB'], JSON_THROW_ON_ERROR)],
        ],
    ];

    $response = $fixture['service']->neighborhood(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 'out', 2, 1,
    );
    $traverse = collect($fixture['client']->commands)->last(
        fn (array $command): bool => str_contains($command['cypher'], 'start_external_id'),
    );

    expect($response['items'])->toHaveCount(1)
        ->and($response['returned'])->toBe(1)
        ->and($response['truncated'])->toBeTrue()
        ->and($response['edges'])->toHaveCount(1)
        ->and($traverse['params']['limit'])->toBe(2);
});

it('validates neighborhood direction and routes bounded depth through exact traversal', function (): void {
    $fixture = dashboardGraphExplorerFixture();

    expect(fn () => $fixture['service']->neighborhood(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        'sideways',
        2,
        10,
    ))->toThrow(InvalidArgumentException::class, 'invalid_direction');

    $response = $fixture['service']->neighborhood(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        'out',
        3,
        7,
    );
    $traverse = collect($fixture['client']->commands)->last(
        fn (array $command): bool => str_contains($command['cypher'], 'start_external_id'),
    );
    $hop = collect($fixture['client']->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'CanonicalGraphAdjacency'),
    );

    expect($response['found'])->toBeTrue()
        ->and($traverse)->not->toBeNull()
        ->and($hop)->not->toBeNull()
        ->and($traverse['cypher'])->toContain('start.external_id = $start_external_id')
        ->and($traverse['cypher'])->toContain('start.source_scope_id = $source_scope_id WITH start')
        ->and($hop['cypher'])->toContain('WHERE target.project_id = $project_id')
        ->and($hop['cypher'])->toContain('target.source_scope_id = $source_scope_id RETURN frontier_id')
        ->not->toContain('CONTAINS')
        ->and($traverse['params'])->toMatchArray([
            'start_external_id' => 'method:InvoiceService::charge',
            'direction' => 'out',
            'max_depth' => 3,
            'limit' => 8,
        ]);
});

it('rejects unknown neighborhood families without broadening the edge predicate', function (): void {
    $fixture = dashboardGraphExplorerFixture();

    expect(fn () => $fixture['service']->neighborhood(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        'out',
        2,
        10,
        ['call', 'not-a-family'],
    ))->toThrow(InvalidArgumentException::class, 'invalid_family');
    expect($fixture['client']->commands)->toBe([]);
});

it('maps a bounded canonical path without exposing internal node or edge ids', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $response = $fixture['service']->path(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        $fixture['handle'],
        2,
        10,
    );

    expect($response['found'])->toBeTrue()
        ->and($response['items'])->toHaveCount(2)
        ->and($response['items'][0]['handle'])->toStartWith('gh1_')
        ->and($response['edges'])->toHaveCount(1)
        ->and($response['edges'][0]['family'])->toBe('call')
        ->and(json_encode($response, JSON_THROW_ON_ERROR))
        ->not->toContain('method:Caller')
        ->not->toContain('edge-path')
        ->not->toContain('/srv/private');
});

it('applies the public path limit to nodes and removes edges to omitted endpoints', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $middleHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], 'method:Middle',
    );
    $endHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], 'method:End',
    );
    $fixture['client']->pathRows = [[
        'nodes' => [
            ['node' => ['external_id' => 'method:InvoiceService::charge', 'public_handle' => $fixture['handle'], 'kind' => 'method', 'public_search_label' => 'Root'], 'labels' => ['Method']],
            ['node' => ['external_id' => 'method:Middle', 'public_handle' => $middleHandle, 'kind' => 'method', 'public_search_label' => 'Middle'], 'labels' => ['Method']],
            ['node' => ['external_id' => 'method:End', 'public_handle' => $endHandle, 'kind' => 'method', 'public_search_label' => 'End'], 'labels' => ['Method']],
        ],
        'edges' => [
            ['external_id' => 'edge-middle', 'type' => 'CALLS_METHOD', 'source_id' => 'method:InvoiceService::charge', 'target_id' => 'method:Middle'],
            ['external_id' => 'edge-end', 'type' => 'CALLS_METHOD', 'source_id' => 'method:Middle', 'target_id' => 'method:End'],
        ],
    ]];

    $response = $fixture['service']->path(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], $fixture['handle'], 4, 1,
    );

    expect($response['items'])->toHaveCount(1)
        ->and($response['returned'])->toBe(1)
        ->and($response['edges'])->toBe([])
        ->and($response['truncated'])->toBeTrue();
});

it('groups bounded reverse impact explanations by public node, kind, family, and edge type', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $response = $fixture['service']->impact(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        10,
    );
    $impact = collect($fixture['client']->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'MATCH (target:CanonicalGraphNode')
            && str_contains($command['cypher'], '[*1..2]'),
    );

    expect($response['found'])->toBeTrue()
        ->and($response['items'])->toHaveCount(1)
        ->and($response['items'][0])->toMatchArray([
            'kind' => 'method',
            'distance' => 1,
            'family' => 'call',
            'edge_types' => ['CALLS_METHOD'],
            'why' => 'call edge CALLS_METHOD',
        ])
        ->and($response['items'][0]['handle'])->toStartWith('gh1_')
        ->and($impact)->not->toBeNull()
        ->and($impact['cypher'])->toContain('public_handle: $public_handle')
        ->toContain('CALLS_METHOD')
        ->toContain('USES_DEPENDENCY')
        ->not->toContain('ROUTES_TO')
        ->not->toContain('TESTS')
        ->not->toContain('READS_TABLE')
        ->and(json_encode($response, JSON_THROW_ON_ERROR))
        ->not->toContain('method:Caller')
        ->not->toContain('/srv/private');
});

it('resolves impact existence before traversal and preserves existing zero-impact nodes', function (): void {
    $keyA = 'impact-existence-key-a';
    config(['app.key' => $keyA]);
    $fixture = dashboardGraphExplorerFixture();
    $missingHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], 'method:Missing',
    );
    $tamperedHandle = substr_replace($fixture['handle'], $fixture['handle'][5] === 'A' ? 'B' : 'A', 5, 1);
    $crossContextHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'], 'workspace_binding', (string) Str::ulid(), $fixture['active_graph_version'], 'method:InvoiceService::charge',
    );
    $fixture['client']->impactRows = [];

    expect($fixture['service']->impact($fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10))
        ->toMatchArray(['found' => true, 'items' => [], 'returned' => 0])
        ->and($fixture['service']->impact($fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $missingHandle, 10))
        ->toMatchArray(['found' => false, 'reason' => 'node_not_found'])
        ->and($fixture['service']->impact($fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $tamperedHandle, 10))
        ->toMatchArray(['found' => false, 'reason' => 'node_not_found'])
        ->and($fixture['service']->impact($fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $crossContextHandle, 10))
        ->toMatchArray(['found' => false, 'reason' => 'node_not_found']);

    config(['app.key' => 'impact-existence-key-b']);
    $fixture['client']->storedKeyFingerprint = hash_hmac('sha256', 'hades.graph.handle.v1', 'impact-existence-key-b');
    $fixture['client']->storedHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'method:InvoiceService::charge',
    );

    expect($fixture['service']->impact($fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 10))
        ->toMatchArray(['found' => false, 'reason' => 'node_not_found']);
});

it('groups impact tiebreakers before applying the public limit', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $callerHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'method:Caller',
    );
    $fixture['client']->impactRows = [
        ['node' => ['external_id' => 'method:Caller', 'public_handle' => $callerHandle, 'kind' => 'method', 'public_search_label' => 'Caller'], 'labels' => ['Method'], 'distance' => 1, 'edge_types' => ['STATIC_CALL']],
        ['node' => ['external_id' => 'method:Caller', 'public_handle' => $callerHandle, 'kind' => 'method', 'public_search_label' => 'Caller'], 'labels' => ['Method'], 'distance' => 1, 'edge_types' => ['CALLS']],
        ['node' => ['external_id' => 'method:Caller', 'public_handle' => $callerHandle, 'kind' => 'method', 'public_search_label' => 'Caller'], 'labels' => ['Method'], 'distance' => 1, 'edge_types' => ['CALLS_METHOD']],
    ];

    $response = $fixture['service']->impact(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        2,
    );
    $impact = collect($fixture['client']->commands)->last(
        fn (array $command): bool => str_contains($command['cypher'], 'MATCH (target:CanonicalGraphNode'),
    );

    expect($response['truncated'])->toBeFalse()
        ->and($response['items'])->toHaveCount(1)
        ->and($response['items'][0]['edge_types'])->toBe(['CALLS', 'CALLS_METHOD', 'STATIC_CALL'])
        ->and($response['items'][0]['why'])->toBe('call edge CALLS,CALLS_METHOD,STATIC_CALL')
        ->and($impact['params']['fetch_limit'])->toBe(3)
        ->and($impact['cypher'])->toContain('ORDER BY distance, affected.public_handle ASC, family ASC')
        ->toContain('LIMIT $fetch_limit');
});

it('does not mark impact truncated at the exact bounded row limit', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $callerHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['active_graph_version'],
        'method:Caller',
    );
    $fixture['client']->impactRows = [
        ['node' => ['external_id' => 'method:Caller', 'public_handle' => $callerHandle, 'kind' => 'method', 'public_search_label' => 'Caller'], 'labels' => ['Method'], 'distance' => 1, 'edge_types' => ['CALLS_METHOD']],
        ['node' => ['external_id' => 'method:Caller', 'public_handle' => $callerHandle, 'kind' => 'method', 'public_search_label' => 'Caller'], 'labels' => ['Method'], 'distance' => 1, 'edge_types' => ['CALLS']],
    ];

    expect($fixture['service']->impact(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        2,
    )['truncated'])->toBeFalse();
});

it('groups impact results by affected node and family before applying the public limit', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $callerHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], 'method:Caller',
    );
    $secondHandle = (new DashboardGraphPublicHandle)->forNode(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['active_graph_version'], 'method:Second',
    );
    $fixture['client']->impactRows = [
        ['node' => ['external_id' => 'method:Caller', 'public_handle' => $callerHandle, 'kind' => 'method', 'public_search_label' => 'Caller'], 'labels' => ['Method'], 'distance' => 2, 'edge_types' => ['CALLS_METHOD']],
        ['node' => ['external_id' => 'method:Caller', 'public_handle' => $callerHandle, 'kind' => 'method', 'public_search_label' => 'Caller'], 'labels' => ['Method'], 'distance' => 1, 'edge_types' => ['EXTENDS']],
        ['node' => ['external_id' => 'method:Caller', 'public_handle' => $callerHandle, 'kind' => 'method', 'public_search_label' => 'Caller'], 'labels' => ['Method'], 'distance' => 1, 'edge_types' => ['CALLS']],
        ['node' => ['external_id' => 'method:Second', 'public_handle' => $secondHandle, 'kind' => 'method', 'public_search_label' => 'Second'], 'labels' => ['Method'], 'distance' => 1, 'edge_types' => ['CALLS']],
    ];

    $response = $fixture['service']->impact(
        $fixture['project_id'], 'workspace_binding', $fixture['scope_id'], $fixture['handle'], 1,
    );
    $impact = collect($fixture['client']->commands)->last(
        fn (array $command): bool => str_contains($command['cypher'], 'MATCH (target:CanonicalGraphNode'),
    );

    expect($response['items'])->toHaveCount(1)
        ->and($response['returned'])->toBe(1)
        ->and($response['truncated'])->toBeTrue()
        ->and($impact['params']['fetch_limit'])->toBe(2)
        ->and($impact['cypher'])->toContain('UNWIND edge_types AS edge_type')
        ->toContain('collect(DISTINCT edge_type) AS edge_types')
        ->toContain('LIMIT $fetch_limit');
});

it('guards impact traversal with the active scope, version, and current handle key', function (): void {
    $fixture = dashboardGraphExplorerFixture();
    $fixture['service']->impact(
        $fixture['project_id'],
        'workspace_binding',
        $fixture['scope_id'],
        $fixture['handle'],
        10,
    );
    $impact = collect($fixture['client']->commands)->last(
        fn (array $command): bool => str_contains($command['cypher'], 'MATCH (target:CanonicalGraphNode'),
    );

    expect($impact['cypher'])
        ->toContain('MATCH (version:CanonicalGraphVersion')
        ->toContain('project_id: $project_id')
        ->toContain('source_scope_type: $source_scope_type')
        ->toContain('source_scope_id: $source_scope_id')
        ->toContain('graph_version: $active_graph_version')
        ->toContain('version.public_handle_key_version = $public_handle_key_version')
        ->toContain('version.public_handle_key_fingerprint = $public_handle_key_fingerprint')
        ->not->toContain('current = true')
        ->and($impact['params'])->toMatchArray([
            'public_handle_key_version' => 'gh1',
            'public_handle_key_fingerprint' => hash_hmac('sha256', 'hades.graph.handle.v1', 'unit-test-app-key'),
        ]);
});

/** @return array{project_id:string,scope_id:string,active_graph_version:string,projection_id:string,artifact_id:string,handle:string,service:DashboardGraphExplorerService,client:Neo4jClient&object} */
function dashboardGraphExplorerFixture(): array
{
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $agentId = (string) Str::ulid();
    $scopeId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $projectionId = (string) Str::ulid();
    $activeGraphVersion = 'active-v2';
    $now = now();

    DB::table('hades_agents')->insert([
        'id' => $agentId,
        'project_id' => $projectId,
        'external_agent_id' => 'explorer-test-'.$agentId,
        'label' => 'Explorer test agent',
        'platform' => 'linux',
        'version' => 'test',
        'declared_capabilities' => '[]',
        'effective_capabilities' => '[]',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('hades_workspace_bindings')->insert([
        'id' => $scopeId,
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'external_agent_id' => 'explorer-test-'.$agentId,
        'workspace_fingerprint' => hash('sha256', $scopeId),
        'display_path' => '/srv/private/explorer-test',
        'head_commit' => str_repeat('a', 40),
        'status' => 'linked',
        'linked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('hades_agent_artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'hades_agent_id' => $agentId,
        'workspace_binding_id' => $scopeId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => '{}',
        'sha256' => hash('sha256', '{}'),
        'truncated' => false,
        'redactions' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('canonical_graph_projections')->insert([
        'id' => $projectionId,
        'project_id' => $projectId,
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => $scopeId,
        'artifact_type' => 'hades_agent_artifact',
        'artifact_id' => $artifactId,
        'graph_version' => 'origin-v1',
        'active_graph_version' => $activeGraphVersion,
        'checksum' => hash('sha256', 'explorer-test'),
        'head_commit' => str_repeat('a', 40),
        'quality' => 'full',
        'status' => 'ready',
        'node_count' => 3,
        'relationship_count' => 2,
        'projected_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $handle = (new DashboardGraphPublicHandle)->forNode(
        $projectId,
        'workspace_binding',
        $scopeId,
        $activeGraphVersion,
        'method:InvoiceService::charge',
    );

    $client = new class($projectId, $scopeId, $activeGraphVersion, $handle) implements Neo4jClient
    {
        /** @var list<array{cypher:string,params:array<string,mixed>}> */
        public array $commands = [];

        public string $storedKeyFingerprint;

        public string $directNodeKind = 'method';

        public bool $directNodeUsesNameOnly = false;

        /** @var list<float> */
        public array $searchScores = [0.9, 0.9, 0.7];

        /** @var list<array<string,mixed>>|null */
        public ?array $searchRows = null;

        /** @var list<list<array<string,mixed>>> */
        public array $searchRowBatches = [];

        /** @var list<array<string,mixed>>|null */
        public ?array $impactRows = null;

        /** @var list<array<string,mixed>>|null */
        public ?array $neighborhoodRows = null;

        /** @var list<array<string,mixed>>|null */
        public ?array $pathRows = null;

        public string $storedHandle;

        public function __construct(
            private readonly string $projectId,
            private readonly string $scopeId,
            private readonly string $activeGraphVersion,
            private readonly string $handle,
        ) {
            $this->storedKeyFingerprint = hash_hmac('sha256', 'hades.graph.handle.v1', (string) config('app.key'));
            $this->storedHandle = $handle;
        }

        /** @param array<string,mixed> $parameters */
        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->commands[] = ['cypher' => $cypher, 'params' => $parameters];

            if (str_contains($cypher, 'RETURN version.public_handle_key_version AS public_handle_key_version')) {
                return [[
                    'public_handle_key_version' => 'gh1',
                    'public_handle_key_fingerprint' => $this->storedKeyFingerprint,
                ]];
            }

            if (str_contains($cypher, 'traversal_schema_version')) {
                return [['traversal_schema_version' => 1]];
            }

            if (str_contains($cypher, 'start.external_id = $start_external_id')) {
                return [[
                    'node' => [
                        'external_id' => 'method:InvoiceService::charge',
                        'public_handle' => $this->handle,
                        'kind' => 'method',
                        'public_search_label' => 'InvoiceService::charge',
                    ],
                    'labels' => ['Method'],
                    'match_fields' => [],
                ]];
            }

            if (str_contains($cypher, 'UNWIND $frontier_ids AS frontier_id')) {
                if ($this->neighborhoodRows !== null) {
                    return $this->neighborhoodRows;
                }

                $callerHandle = (new DashboardGraphPublicHandle)->forNode(
                    $this->projectId,
                    'workspace_binding',
                    $this->scopeId,
                    $this->activeGraphVersion,
                    'method:Caller',
                );

                return [[
                    'source_id' => 'method:InvoiceService::charge',
                    'node' => [
                        'external_id' => 'method:Caller',
                        'public_handle' => $callerHandle,
                        'kind' => 'method',
                        'public_search_label' => 'Caller',
                    ],
                    'labels' => ['Method'],
                    'edge' => [
                        'edge_json' => json_encode([
                            'external_id' => 'edge-neighbor',
                            'type' => 'CALLS_METHOD',
                            'source_id' => 'method:InvoiceService::charge',
                            'target_id' => 'method:Caller',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]];
            }

            if (str_contains($cypher, 'MATCH (target:CanonicalGraphNode') && str_contains($cypher, '[*1..2]')) {
                if ($this->impactRows !== null) {
                    return $this->impactRows;
                }

                return [[
                    'node' => [
                        'external_id' => 'method:Caller',
                        'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                            $this->projectId,
                            'workspace_binding',
                            $this->scopeId,
                            $this->activeGraphVersion,
                            'method:Caller',
                        ),
                        'kind' => 'method',
                        'public_search_label' => 'Caller',
                    ],
                    'labels' => ['CanonicalGraphNode', 'Method'],
                    'distance' => 1,
                    'edge_types' => ['CALLS_METHOD'],
                ]];
            }

            if (str_contains($cypher, 'RETURN [n IN nodes(p)')) {
                if ($this->pathRows !== null) {
                    return $this->pathRows;
                }

                $callerHandle = (new DashboardGraphPublicHandle)->forNode(
                    $this->projectId,
                    'workspace_binding',
                    $this->scopeId,
                    $this->activeGraphVersion,
                    'method:Caller',
                );

                return [[
                    'nodes' => [
                        ['node' => [
                            'external_id' => 'method:InvoiceService::charge',
                            'public_handle' => $this->handle,
                            'kind' => 'method',
                            'public_search_label' => 'InvoiceService::charge',
                        ], 'labels' => ['Method']],
                        ['node' => [
                            'external_id' => 'method:Caller',
                            'public_handle' => $callerHandle,
                            'kind' => 'method',
                            'public_search_label' => 'Caller',
                        ], 'labels' => ['Method']],
                    ],
                    'edges' => [[
                        'external_id' => 'edge-path',
                        'type' => 'CALLS_METHOD',
                        'source_id' => 'method:InvoiceService::charge',
                        'target_id' => 'method:Caller',
                    ]],
                ]];
            }

            if (str_contains($cypher, 'RETURN properties(result) AS node')) {
                return [[
                    'node' => [
                        'external_id' => 'method:Caller',
                        'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                            $this->projectId,
                            'workspace_binding',
                            $this->scopeId,
                            $this->activeGraphVersion,
                            'method:Caller',
                        ),
                        'kind' => 'method',
                        'public_search_label' => 'Caller',
                    ],
                    'labels' => ['CanonicalGraphNode', 'Method'],
                    'edge' => [
                        'external_id' => 'edge-neighbor',
                        'type' => 'CALLS_METHOD',
                        'source_id' => 'method:Caller',
                        'target_id' => 'method:InvoiceService::charge',
                    ],
                    'edge_type' => 'CALLS_METHOD',
                ]];
            }

            if (str_contains($cypher, 'db.index.fulltext.queryNodes')) {
                $withVersion = function (array $rows): array {
                    return array_map(fn (array $row): array => [
                        'version_project_key' => 'gh1',
                        'version_source_fingerprint' => $this->storedKeyFingerprint,
                        ...$row,
                    ], $rows);
                };
                if ($this->searchRowBatches !== []) {
                    return $withVersion(array_slice(
                        array_shift($this->searchRowBatches),
                        0,
                        (int) ($parameters['fetch_limit'] ?? PHP_INT_MAX),
                    ));
                }
                if ($this->searchRows !== null) {
                    return $withVersion($this->searchRows);
                }

                $makeNode = function (string $externalId, string $label): array {
                    return [
                        'external_id' => $externalId,
                        'public_handle' => (new DashboardGraphPublicHandle)->forNode(
                            $this->projectId,
                            'workspace_binding',
                            $this->scopeId,
                            $this->activeGraphVersion,
                            $externalId,
                        ),
                        'kind' => 'method',
                        'public_search_name' => $label,
                        'public_search_label' => $label,
                        'path' => '/srv/private/'.$label.'.php',
                    ];
                };

                return $withVersion([
                    ['node' => $makeNode('method:Beta', 'Beta'), 'labels' => ['Method'], 'score' => $this->searchScores[0] ?? 0.9],
                    ['node' => $makeNode('method:Alpha', 'Alpha'), 'labels' => ['Method'], 'score' => $this->searchScores[1] ?? 0.9],
                    ['node' => $makeNode('method:Gamma', 'Gamma'), 'labels' => ['Method'], 'score' => $this->searchScores[2] ?? 0.7],
                ]);
            }

            if (! str_contains($cypher, 'MATCH (node:CanonicalGraphNode')) {
                return [];
            }

            if (($parameters['project_id'] ?? null) !== $this->projectId) {
                return [];
            }
            if (($parameters['source_scope_type'] ?? null) !== 'workspace_binding'
                || ($parameters['source_scope_id'] ?? null) !== $this->scopeId
                || ($parameters['active_graph_version'] ?? null) !== $this->activeGraphVersion
                || ($parameters['public_handle_key_version'] ?? null) !== 'gh1') {
                return [];
            }

            if (isset($parameters['public_handle'])) {
                $node = ($parameters['public_handle'] === $this->storedHandle) ? [
                    'external_id' => 'method:InvoiceService::charge',
                    'public_handle' => $this->storedHandle,
                    'kind' => $this->directNodeKind,
                    ...($this->directNodeUsesNameOnly
                        ? ['public_search_name' => 'InvoiceService::charge']
                        : ['public_search_label' => 'InvoiceService::charge']),
                    'path' => '/srv/private/InvoiceService.php',
                ] : null;

                return [[
                    'version_project_key' => 'gh1',
                    'version_source_fingerprint' => $this->storedKeyFingerprint,
                    'node' => $node,
                    'labels' => ['CanonicalGraphNode', 'Method'],
                ]];
            }

            return [];
        }
    };

    return [
        'project_id' => $projectId,
        'scope_id' => $scopeId,
        'active_graph_version' => $activeGraphVersion,
        'projection_id' => $projectionId,
        'artifact_id' => $artifactId,
        'handle' => $handle,
        'service' => new DashboardGraphExplorerService(new CanonicalGraphQueryService($client), $client),
        'client' => $client,
    ];
}
