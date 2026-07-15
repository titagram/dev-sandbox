<?php

use App\Services\Graph\CanonicalGraphProjectionService;
use App\Services\Graph\DashboardGraphPublicHandle;
use App\Services\Graph\Neo4jCanonicalGraphProjector;
use App\Services\Neo4j\FakeNeo4jClient;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.key' => 'unit-test-app-key']);
    $this->seed(DevBoardSeeder::class);
});

afterEach(function (): void {
    config(['app.key' => 'unit-test-app-key']);
});

it('persists bounded graph coverage with each projection candidate', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $graph = canonicalProjectionGraph($projectId, 'artifact-coverage', str_repeat('9', 64));
    $graph['contract']['extractor']['quality'] = 'partial';
    $graph['contract']['coverage'] = [
        'languages' => ['php', 'typescript'],
        'files_total' => 12,
        'files_analyzed' => 9,
        'files_failed' => 3,
        'files_budget_omitted' => 2,
        'routes_promoted' => 4,
        'routes_omitted' => 1,
        'tests_promoted' => 3,
        'tests_omitted' => 1,
        'nodes_capacity_omitted' => 5,
    ];

    $projection = app(CanonicalGraphProjectionService::class)->queue($graph);

    expect(json_decode((string) $projection->coverage, true, flags: JSON_THROW_ON_ERROR))
        ->toBe($graph['contract']['coverage']);
});

it('keeps the previous projection ready until its replacement is ready', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $first = $service->queue(canonicalProjectionGraph($projectId, 'artifact-1', str_repeat('a', 64)));
    $service->markProjecting($first->id);
    $service->markReady($first->id, 10, 5, canonicalProjectionMarker($first));
    $second = $service->queue(canonicalProjectionGraph($projectId, 'artifact-2', str_repeat('b', 64)));
    $service->markProjecting($second->id);
    expect($service->readyForScope($projectId, 'workspace_binding', 'binding-1')->id)->toBe($first->id);
    $service->markReady($second->id, 12, 7, canonicalProjectionMarker($second));
    expect($service->readyForScope($projectId, 'workspace_binding', 'binding-1')->id)->toBe($second->id)
        ->and(DB::table('canonical_graph_projections')->where('id', $first->id)->value('status'))->toBe('stale')
        ->and(DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', 'workspace_binding')
            ->where('source_scope_id', 'binding-1')
            ->where('status', 'ready')
            ->count())->toBe(1);
});

it('materializes opaque handles and only sanitized fulltext fields', function (): void {
    config(['app.key' => 'unit-test-app-key']);
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $projection = $service->queue(canonicalProjectionGraph($projectId, 'artifact-public-handle', str_repeat('a', 64)));
    $client = new FakeNeo4jClient;
    $graph = [
        'nodes' => [[
            'id' => 'method:InvoiceService::charge',
            'labels' => ['Symbol', 'Method'],
            'properties' => [
                'kind' => 'method',
                'name' => 'InvoiceService::charge',
                'path' => '/srv/private/app/InvoiceService.php',
            ],
        ]],
        'relationships' => [],
    ];

    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);

    $fulltext = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'CREATE FULLTEXT INDEX canonical_node_search'),
    );
    $lookup = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'canonical_node_public_lookup'),
    );
    $nodeBatch = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes AS node'),
    );

    expect($fulltext['cypher'])->toContain('n.graph_version')
        ->toContain('n.public_search_name')
        ->toContain('n.public_search_label')
        ->toContain('n.public_search_path')
        ->toContain('n.public_search_terms')
        ->not->toContain('n.external_id')
        ->not->toContain('n.path')
        ->and($lookup['cypher'])->toContain('project_id, n.source_scope_type, n.source_scope_id, n.graph_version, n.public_handle')
        ->and($nodeBatch['params']['nodes'][0]['properties']['public_handle'])->toStartWith('gh1_')
        ->and($nodeBatch['params']['nodes'][0]['properties']['public_search_name'])->toBe('InvoiceService::charge')
        ->and($nodeBatch['params']['nodes'][0]['properties']['public_search_terms'])->toContain('invoice')
        ->toContain('service')
        ->and($nodeBatch['params']['nodes'][0]['properties']['public_search_path'])->toBeNull();
});

it('rejects filesystem identities from public fields while preserving legitimate route paths', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-path-sanitization', str_repeat('f', 64)));
    $graph = [
        'nodes' => [
            [
                'id' => 'method:local-identity',
                'labels' => ['Method'],
                'properties' => [
                    'kind' => 'method',
                    'name' => '/home/ubuntu/dev-sandbox/Secret.php',
                    'label' => '/home/ubuntu/dev-sandbox/Secret.php',
                ],
            ],
            ['id' => 'route:posix', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'path' => '/srv/private/app/Invoice.php']],
            ['id' => 'route:drive-backslash', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'path' => 'C:\\workspace\\Invoice.php']],
            ['id' => 'route:drive-slash', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'path' => 'C:/workspace/Invoice.php']],
            ['id' => 'route:drive-relative', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'path' => 'C:workspace\\Invoice.php']],
            ['id' => 'route:unc', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'path' => '\\\\server\\private\\Invoice.php']],
            ['id' => 'route:file-uri', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'path' => 'file:///srv/private/Invoice.php']],
            ['id' => 'route:embedded', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'source' => 'route_registry', 'path' => 'source=/data/private/Foo.php']],
            ['id' => 'route:unproven-legitimate', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'path' => '/api/invoices/{id}']],
            ['id' => 'route:legitimate', 'labels' => ['Route'], 'properties' => ['kind' => 'route', 'source' => 'route_registry', 'path' => '/api/invoices/{id}']],
            ['id' => 'method:no-label', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => 'InvoiceService::charge']],
            ['id' => 'method:data-path', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => '/data/private/Foo.php', 'label' => '/data/private/Foo.php']],
            ['id' => 'method:applications-path', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => '/Applications/Foo.php', 'label' => '/Applications/Foo.php']],
            ['id' => 'method:dot-relative', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => './src/Foo.ts', 'label' => './src/Foo.ts']],
            ['id' => 'method:parent-relative', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => '../backend/app/Foo.php', 'label' => '../backend/app/Foo.php']],
            ['id' => 'method:drive-relative', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => 'C:workspace\\Foo.php', 'label' => 'C:workspace\\Foo.php']],
            ['id' => 'method:embedded-path', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => 'source=/data/private/Foo.php', 'label' => 'source=/data/private/Foo.php']],
        ],
        'relationships' => [],
        'private_route_provenance' => [
            'route:legitimate' => true,
        ],
    ];
    $client = new FakeNeo4jClient;

    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);
    $nodeBatch = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes AS node'),
    );
    $properties = collect($nodeBatch['params']['nodes'])->keyBy('id')->map(fn (array $node): array => $node['properties']);

    expect($properties['method:local-identity']['public_search_name'])->toBeNull()
        ->and($properties['method:local-identity']['public_search_label'])->toBeNull()
        ->and($properties['route:posix']['public_search_path'])->toBeNull()
        ->and($properties['route:drive-backslash']['public_search_path'])->toBeNull()
        ->and($properties['route:drive-slash']['public_search_path'])->toBeNull()
        ->and($properties['route:drive-relative']['public_search_path'])->toBeNull()
        ->and($properties['route:unc']['public_search_path'])->toBeNull()
        ->and($properties['route:file-uri']['public_search_path'])->toBeNull()
        ->and($properties['route:embedded']['public_search_path'])->toBeNull()
        ->and($properties['route:unproven-legitimate']['public_search_path'])->toBeNull()
        ->and($properties['route:legitimate']['public_search_path'])->toBe('/api/invoices/{id}')
        ->and($properties['method:no-label']['public_search_label'])->toBeNull()
        ->and($properties['method:data-path']['public_search_name'])->toBeNull()
        ->and($properties['method:data-path']['public_search_label'])->toBeNull()
        ->and($properties['method:applications-path']['public_search_name'])->toBeNull()
        ->and($properties['method:applications-path']['public_search_label'])->toBeNull()
        ->and($properties['method:dot-relative']['public_search_name'])->toBeNull()
        ->and($properties['method:dot-relative']['public_search_label'])->toBeNull()
        ->and($properties['method:parent-relative']['public_search_name'])->toBeNull()
        ->and($properties['method:parent-relative']['public_search_label'])->toBeNull()
        ->and($properties['method:drive-relative']['public_search_name'])->toBeNull()
        ->and($properties['method:drive-relative']['public_search_label'])->toBeNull()
        ->and($properties['method:embedded-path']['public_search_name'])->toBeNull()
        ->and($properties['method:embedded-path']['public_search_label'])->toBeNull();
});

it('rejects common local roots and relative source paths while preserving dotted routes', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-common-path-roots', str_repeat('i', 64)));
    $paths = [
        '/root/Secret.php',
        '/etc/passwd',
        '/mnt/workspace/Foo.php',
        '/Volumes/private/Foo.php',
        '/app/Foo.php',
    ];
    $graph = [
        'nodes' => array_merge(
            array_map(static fn (string $path, int $index): array => [
                'id' => 'route:local-root-'.$index,
                'labels' => ['Route'],
                'properties' => ['kind' => 'route', 'path' => $path],
            ], $paths, array_keys($paths)),
            [
                [
                    'id' => 'method:relative-php',
                    'labels' => ['Method'],
                    'properties' => ['kind' => 'method', 'name' => 'backend/app/Foo.php', 'label' => 'backend/app/Foo.php'],
                ],
                [
                    'id' => 'method:relative-ts',
                    'labels' => ['Method'],
                    'properties' => ['kind' => 'method', 'name' => 'src/Foo.ts', 'label' => 'src/Foo.ts'],
                ],
                [
                    'id' => 'route:dotted',
                    'labels' => ['Route'],
                    'properties' => ['kind' => 'route', 'source' => 'route_registry', 'path' => '/.well-known/openid-configuration'],
                ],
            ],
        ),
        'relationships' => [],
        'private_route_provenance' => [
            'route:dotted' => true,
        ],
    ];
    $client = new FakeNeo4jClient;

    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);
    $nodeBatch = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes AS node'),
    );
    $properties = collect($nodeBatch['params']['nodes'])->keyBy('id')->map(fn (array $node): array => $node['properties']);

    foreach (array_keys($paths) as $index) {
        expect($properties['route:local-root-'.$index]['public_search_path'])->toBeNull();
    }
    expect($properties['method:relative-php']['public_search_name'])->toBeNull()
        ->and($properties['method:relative-php']['public_search_label'])->toBeNull()
        ->and($properties['method:relative-ts']['public_search_name'])->toBeNull()
        ->and($properties['method:relative-ts']['public_search_label'])->toBeNull()
        ->and($properties['route:dotted']['public_search_path'])->toBe('/.well-known/openid-configuration');
});

it('publishes trusted non-api multi-segment routes while hiding untrusted paths', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-non-api-route', str_repeat('k', 64)));
    $graph = [
        'nodes' => [
            [
                'id' => 'route:trusted-wiki',
                'labels' => ['Route'],
                'properties' => ['kind' => 'route', 'path' => '/projects/{id}/wiki'],
            ],
            [
                'id' => 'route:unproven-wiki',
                'labels' => ['Route'],
                'properties' => ['kind' => 'route', 'path' => '/projects/{id}/wiki'],
            ],
        ],
        'relationships' => [],
        'private_route_provenance' => [
            'route:trusted-wiki' => true,
        ],
    ];
    $client = new FakeNeo4jClient;

    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);
    $nodeBatch = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes AS node'),
    );
    $properties = collect($nodeBatch['params']['nodes'])->keyBy('id')->map(fn (array $node): array => $node['properties']);

    expect($properties['route:trusted-wiki']['public_search_path'])->toBe('/projects/{id}/wiki')
        ->and($properties['route:unproven-wiki']['public_search_path'])->toBeNull();
});

it('preserves valid PHP namespaces while rejecting Windows and source-path identities', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-fqcn-boundaries', str_repeat('j', 64)));
    $valid = [
        'App\\Services\\InvoiceService',
        'Domain\\Billing\\ChargeInvoice',
        '\\App\\Services\\InvoiceService',
    ];
    $invalid = [
        'C:\\workspace\\Foo.php',
        'C:workspace\\Foo.php',
        '.\\src\\Foo.php',
        '..\\backend\\Foo.php',
        '\\\\server\\share\\Foo.php',
        '\\\\?\\C:\\Foo.php',
        'source=C:\\Foo.php',
        'src\\Foo.php',
    ];
    $nodes = [];
    foreach ([...$valid, ...$invalid] as $index => $value) {
        $nodes[] = [
            'id' => 'class:boundary-'.$index,
            'labels' => ['Class'],
            'properties' => [
                'kind' => 'class',
                'name' => $value,
                'label' => $value,
            ],
        ];
    }
    $client = new FakeNeo4jClient;

    app(Neo4jCanonicalGraphProjector::class)->project(['nodes' => $nodes, 'relationships' => []], $projection, $client);
    $nodeBatch = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes AS node'),
    );
    $properties = collect($nodeBatch['params']['nodes'])->keyBy('id')->map(fn (array $node): array => $node['properties']);

    foreach (array_keys($valid) as $index) {
        expect($properties['class:boundary-'.$index]['public_search_name'])->toBe($valid[$index])
            ->and($properties['class:boundary-'.$index]['public_search_label'])->toBe($valid[$index]);
    }
    foreach (array_keys($invalid) as $offset) {
        $index = count($valid) + $offset;
        expect($properties['class:boundary-'.$index]['public_search_name'])->toBeNull()
            ->and($properties['class:boundary-'.$index]['public_search_label'])->toBeNull();
    }
});

it('persists relationship source and target identities for path endpoint materialization', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-edge-endpoints', str_repeat('g', 64)));
    $graph = [
        'nodes' => [
            ['id' => 'method:Caller', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => 'Caller']],
            ['id' => 'method:Target', 'labels' => ['Method'], 'properties' => ['kind' => 'method', 'name' => 'Target']],
        ],
        'relationships' => [[
            'id' => 'edge:caller-target',
            'source_id' => 'method:Caller',
            'target_id' => 'method:Target',
            'type' => 'CALLS_METHOD',
            'properties' => ['weight' => 1],
        ]],
    ];
    $client = new FakeNeo4jClient;

    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);
    $relationshipBatch = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $relationships AS relationship'),
    );
    $edgeProperties = $relationshipBatch['params']['relationships'][0]['properties'];

    expect($edgeProperties)->toMatchArray([
        'weight' => 1,
        'source_id' => 'method:Caller',
        'target_id' => 'method:Target',
    ]);
});

it('creates edge-type composite indexes for deterministic adjacency traversal', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-adjacency-indexes', str_repeat('h', 64)));
    $client = new FakeNeo4jClient;

    app(Neo4jCanonicalGraphProjector::class)->project([
        'nodes' => [],
        'relationships' => [],
    ], $projection, $client);
    $indexCyphers = collect($client->commands)
        ->filter(fn (array $command): bool => str_contains($command['cypher'], 'CREATE INDEX canonical_adjacency_'))
        ->pluck('cypher')
        ->all();

    $historicalNames = ['canonical_adjacency_direction_rank', 'canonical_adjacency_any_rank'];
    $newNames = ['canonical_adjacency_direction_edge_type_rank_v2', 'canonical_adjacency_any_edge_type_rank_v2'];
    $historicalDefinitions = [
        'CREATE INDEX canonical_adjacency_direction_rank IF NOT EXISTS FOR (a:CanonicalGraphAdjacency) ON (a.graph_version, a.from_external_id, a.direction, a.direction_rank)',
        'CREATE INDEX canonical_adjacency_any_rank IF NOT EXISTS FOR (a:CanonicalGraphAdjacency) ON (a.graph_version, a.from_external_id, a.any_rank)',
    ];

    expect($indexCyphers)->toContain('CREATE INDEX canonical_adjacency_direction_edge_type_rank_v2 IF NOT EXISTS FOR (a:CanonicalGraphAdjacency) ON (a.graph_version, a.from_external_id, a.direction, a.edge_type, a.direction_rank)')
        ->and($indexCyphers)->toContain('CREATE INDEX canonical_adjacency_any_edge_type_rank_v2 IF NOT EXISTS FOR (a:CanonicalGraphAdjacency) ON (a.graph_version, a.from_external_id, a.edge_type, a.any_rank)')
        ->and(array_intersect($newNames, $historicalNames))->toBe([])
        ->and(array_intersect($historicalDefinitions, $indexCyphers))->toBe([])
        ->and(collect($indexCyphers)->filter(fn (string $query): bool => str_contains($query, $newNames[0]))->first())->toContain('a.edge_type, a.direction_rank')
        ->and(collect($indexCyphers)->filter(fn (string $query): bool => str_contains($query, $newNames[1]))->first())->toContain('a.edge_type, a.any_rank');
});

it('stamps the public handle key identity and binds handles to the physical candidate graph version', function (): void {
    config(['app.key' => 'unit-test-app-key']);
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-rotation-metadata', str_repeat('c', 64)));
    $projection->logical_graph_version = 'origin-v1';
    $projection->graph_version = 'published-v2';
    $projection->active_graph_version = 'old-v1';
    $client = new FakeNeo4jClient;
    $graph = [
        'nodes' => [[
            'id' => 'method:Rotation::run',
            'labels' => ['Method'],
            'properties' => ['kind' => 'method', 'name' => 'Rotation::run'],
        ]],
        'relationships' => [],
    ];

    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);

    $version = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'MERGE (v:CanonicalGraphVersion'),
    );
    $nodeBatch = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes AS node'),
    );

    expect($version['params']['metadata']['public_handle_key_version'])->toBe('gh1')
        ->and($version['params']['metadata']['public_handle_key_fingerprint'])
        ->toBe(hash_hmac('sha256', 'hades.graph.handle.v1', 'unit-test-app-key'))
        ->and($nodeBatch['params']['nodes'][0]['properties']['public_handle'])
        ->toBe(app(DashboardGraphPublicHandle::class)->forNode(
            $projectId,
            'workspace_binding',
            'binding-1',
            'published-v2',
            'method:Rotation::run',
        ));
});

it('does not persist technical legacy values into public search fields', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-legacy-search-values', str_repeat('e', 64)));
    $graph = [
        'nodes' => [
            [
                'id' => 'method:LegacyHades',
                'labels' => ['Method'],
                'properties' => [
                    'kind' => 'method',
                    'name' => 'hades-public-v1-node-legacy',
                    'label' => 'hades-public-v1-label-legacy',
                ],
            ],
            [
                'id' => 'method:LegacyTechnical',
                'labels' => ['Method'],
                'properties' => [
                    'kind' => 'method',
                    'name' => 'node-legacy-123',
                    'label' => 'edge-legacy-123',
                ],
            ],
        ],
        'relationships' => [],
    ];
    $client = new FakeNeo4jClient;

    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);
    $nodeBatch = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes AS node'),
    );
    $properties = array_column($nodeBatch['params']['nodes'], 'properties');

    expect($properties[0]['public_search_name'])->toBeNull()
        ->and($properties[0]['public_search_label'])->toBeNull()
        ->and($properties[1]['public_search_name'])->toBeNull()
        ->and($properties[1]['public_search_label'])->toBeNull();
});

it('rotates public handles through a forced candidate and atomic publication', function (): void {
    $keyA = 'rotation-key-a';
    $keyB = 'rotation-key-b';
    config(['app.key' => $keyA]);
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $identityGraph = canonicalProjectionGraph($projectId, 'artifact-rotation-lifecycle', str_repeat('d', 64));
    $initial = $service->queue($identityGraph);
    $service->markProjecting($initial->id);
    $service->markReady($initial->id, 1, 0, function (): void {});
    $publishedBefore = DB::table('canonical_graph_projections')->where('id', $initial->id)->firstOrFail();
    $oldHandle = app(DashboardGraphPublicHandle::class)->forNode(
        $projectId,
        'workspace_binding',
        'binding-1',
        (string) $publishedBefore->active_graph_version,
        'method:Rotation::run',
    );

    $claim = $service->acquireForForcedRebuild($identityGraph);
    expect($claim['claimed'])->toBeTrue();
    $candidate = $claim['projection'];
    expect($candidate->logical_graph_version)->toBe($publishedBefore->graph_version)
        ->and($candidate->active_graph_version)->toBe($publishedBefore->active_graph_version)
        ->and($candidate->graph_version)->not->toBe($publishedBefore->graph_version);

    config(['app.key' => $keyB]);
    $client = new FakeNeo4jClient;
    $physicalGraph = [
        'nodes' => [[
            'id' => 'method:Rotation::run',
            'labels' => ['Method'],
            'properties' => ['kind' => 'method', 'name' => 'Rotation::run'],
        ]],
        'relationships' => [],
    ];
    $projector = app(Neo4jCanonicalGraphProjector::class);
    $counts = $projector->project($physicalGraph, $candidate, $client);
    $versionCommand = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'MERGE (v:CanonicalGraphVersion'),
    );
    $nodeCommand = collect($client->commands)->first(
        fn (array $command): bool => str_contains($command['cypher'], 'UNWIND $nodes AS node'),
    );
    $candidateHandle = app(DashboardGraphPublicHandle::class)->forNode(
        $projectId,
        'workspace_binding',
        'binding-1',
        (string) $candidate->graph_version,
        'method:Rotation::run',
    );

    $published = $service->publishPublicationAttempt(
        $claim['attempt_id'],
        $claim['owner_token'],
        $counts['nodes'],
        $counts['relationships'],
        fn (): mixed => $projector->publishCurrent($candidate, $client),
    );
    $publishedAfter = DB::table('canonical_graph_projections')->where('id', $initial->id)->firstOrFail();

    expect($versionCommand['params']['metadata']['public_handle_key_fingerprint'])
        ->toBe(hash_hmac('sha256', 'hades.graph.handle.v1', $keyB))
        ->not->toBe(hash_hmac('sha256', 'hades.graph.handle.v1', $keyA))
        ->and($nodeCommand['params']['nodes'][0]['properties']['public_handle'])->toBe($candidateHandle)
        ->and($candidateHandle)->not->toBe($oldHandle)
        ->and($published)->not->toBeNull()
        ->and($publishedAfter->status)->toBe('ready')
        ->and($publishedAfter->active_graph_version)->toBe($candidate->graph_version)
        ->and($publishedAfter->active_graph_version)->not->toBe($publishedBefore->active_graph_version);
});

it('fails closed when APP_KEY is unavailable instead of storing a null public handle', function (): void {
    config(['app.key' => null]);
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $projection = app(CanonicalGraphProjectionService::class)
        ->queue(canonicalProjectionGraph($projectId, 'artifact-missing-key', str_repeat('b', 64)));
    $graph = [
        'nodes' => [[
            'id' => 'method:MissingKey',
            'labels' => ['Method'],
            'properties' => ['kind' => 'method', 'name' => 'MissingKey'],
        ]],
        'relationships' => [],
    ];
    $client = new FakeNeo4jClient;

    expect(fn (): array => app(Neo4jCanonicalGraphProjector::class)
        ->project($graph, $projection, $client))
        ->toThrow(InvalidArgumentException::class, 'invalid_handle');
    expect($client->commands)->toBe([]);
});

it('locks the stable project row before locking the projection candidate', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $candidate = $service->queue(canonicalProjectionGraph($projectId, 'artifact-1', str_repeat('a', 64)));
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $service->markReady($candidate->id, 10, 5, canonicalProjectionMarker($candidate));

    $projectLock = array_find_key($queries, fn (string $sql): bool => str_contains($sql, 'from "projects"'));
    $candidateLock = array_find_key($queries, fn (string $sql): bool => str_starts_with($sql, 'select *') && str_contains($sql, 'from "canonical_graph_projections"'));
    expect($projectLock)->not->toBeNull()
        ->and($candidateLock)->not->toBeNull()
        ->and($projectLock)->toBeLessThan($candidateLock);
});

it('queues an artifact identity idempotently', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $graph = canonicalProjectionGraph($projectId, 'artifact-1', str_repeat('a', 64));
    $first = $service->queue($graph);
    $second = $service->queue($graph);
    expect($second->id)->toBe($first->id)
        ->and(DB::table('canonical_graph_projections')->count())->toBe(1);
});

it('stores only bounded failure codes and rejects raw exception text', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $raw = $service->queue(canonicalProjectionGraph($projectId, 'artifact-1', str_repeat('a', 64)));
    $bounded = $service->queue(canonicalProjectionGraph($projectId, 'artifact-2', str_repeat('b', 64)));
    $service->markFailed($raw->id, 'SQLSTATE[HY000]: secret connection text');
    $service->markFailed($bounded->id, 'neo4j_timeout');
    expect(DB::table('canonical_graph_projections')->where('id', $raw->id)->value('error_code'))->toBe('projection_failed')
        ->and(DB::table('canonical_graph_projections')->where('id', $bounded->id)->value('error_code'))->toBe('neo4j_timeout');
});

it('claims a queued worker projection exactly once with an atomic state transition', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $projection = $service->queue(canonicalProjectionGraph($projectId, 'artifact-claim', str_repeat('c', 64)));

    $firstClaimed = $service->claimForWorker($projection->id);
    $secondClaimed = $service->claimForWorker($projection->id);

    expect($firstClaimed)->toBeTrue()
        ->and($secondClaimed)->toBeFalse()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('projecting');
});

it('atomically claims an exact completed projection for a forced rebuild', function (string $status) {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $graph = canonicalProjectionGraph($projectId, 'artifact-force-'.$status, str_repeat('7', 64));
    $projection = $service->queue($graph);
    DB::table('canonical_graph_projections')->where('id', $projection->id)->update([
        'status' => $status,
        'error_code' => $status === 'failed' ? 'neo4j_unavailable' : null,
    ]);

    $claimed = $service->claimForForcedRebuild($projection->id, $graph);

    expect($claimed)->toBeTrue()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe($status)
        ->and(DB::table('canonical_graph_projection_attempts')->where('projection_id', $projection->id)->where('status', 'projecting')->count())->toBe(1);
})->with(['ready', 'stale', 'failed']);

it('allows only one forced rebuild owner and leaves active work untouched', function (string $initialStatus) {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $graph = canonicalProjectionGraph($projectId, 'artifact-force-owner-'.$initialStatus, str_repeat('8', 64));
    $projection = $service->queue($graph);
    DB::table('canonical_graph_projections')->where('id', $projection->id)->update(['status' => $initialStatus]);

    $first = $service->claimForForcedRebuild($projection->id, $graph);
    $second = $service->claimForForcedRebuild($projection->id, $graph);

    expect($first)->toBe($initialStatus === 'ready')
        ->and($second)->toBeFalse()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))
        ->toBe($initialStatus);
})->with(['ready', 'queued', 'projecting']);

it('refuses a forced rebuild when checksum or graph version is not exact', function (string $field) {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $graph = canonicalProjectionGraph($projectId, 'artifact-force-conflict-'.$field, str_repeat('9', 64));
    $projection = $service->queue($graph);
    DB::table('canonical_graph_projections')->where('id', $projection->id)->update([
        'status' => 'ready',
        $field => str_repeat('0', 64),
    ]);

    expect($service->claimForForcedRebuild($projection->id, $graph))->toBeFalse()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('ready');
})->with(['checksum', 'graph_version']);

it('records a failed forced attempt without demoting the verified projection', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $graph = canonicalProjectionGraph($projectId, 'artifact-force-reconcile', str_repeat('6', 64));
    $projection = $service->queue($graph);
    DB::table('canonical_graph_projections')->where('id', $projection->id)->update(['status' => 'ready']);
    $claim = $service->acquireForForcedRebuild($graph);
    expect($claim['claimed'])->toBeTrue();

    expect($service->markForcedRebuildFailed(
        $claim['attempt_id'], $claim['owner_token'], 'neo4j_unavailable',
    ))->toBeTrue()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('ready')
        ->and(DB::table('canonical_graph_projection_attempts')->where('id', $claim['attempt_id'])->value('status'))->toBe('failed');

    $reconcile = $service->claimForReconcile([$graph])["hades_agent_artifact\0artifact-force-reconcile"];
    expect($reconcile['claimed'])->toBeFalse()
        ->and($reconcile['conflict'])->toBeFalse()
        ->and($reconcile['projection']->status)->toBe('ready');
});

it('returns the atomic claim result without a fallible post claim read', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $projection = $service->queue(canonicalProjectionGraph($projectId, 'artifact-no-post-read', str_repeat('d', 64)));
    $rejectPostClaimRead = true;
    $failure = null;
    $claimed = null;

    DB::listen(function ($query) use (&$rejectPostClaimRead): void {
        $sql = strtolower($query->sql);
        if ($rejectPostClaimRead && str_starts_with($sql, 'select') && str_contains($sql, 'canonical_graph_projections')) {
            throw new RuntimeException('simulated post-CAS read failure');
        }
    });

    try {
        $claimed = $service->claimForWorker($projection->id);
    } catch (Throwable $exception) {
        $failure = $exception;
    } finally {
        $rejectPostClaimRead = false;
    }

    expect($failure)->toBeNull()
        ->and($claimed)->toBeTrue()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('projecting');
});

it('ignores final failure unless the projection is still queued', function (string $status) {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $projection = $service->queue(canonicalProjectionGraph($projectId, 'artifact-final-'.$status, str_repeat('1', 64)));
    DB::table('canonical_graph_projections')->where('id', $projection->id)->update([
        'status' => $status,
        'error_code' => null,
    ]);

    $changed = $service->markFailedIfQueued($projection->id, 'neo4j_unavailable');

    expect($changed)->toBeFalse()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe($status)
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBeNull();
})->with(['projecting', 'ready', 'stale', 'failed']);

it('refuses a stale ready transition without staling or publishing anything', function (string $candidateStatus) {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $current = $service->queue(canonicalProjectionGraph($projectId, 'artifact-current', str_repeat('e', 64)));
    $service->markProjecting($current->id);
    expect($service->markReady($current->id, 10, 5, canonicalProjectionMarker($current)))->toBeTrue();

    $candidate = $service->queue(canonicalProjectionGraph($projectId, 'artifact-stale-worker', str_repeat('f', 64)));
    DB::table('canonical_graph_projections')->where('id', $candidate->id)->update([
        'status' => $candidateStatus,
        'node_count' => null,
        'relationship_count' => null,
        'projected_at' => null,
    ]);
    $beforeCurrent = DB::table('canonical_graph_projections')->where('id', $current->id)->first();
    $beforeCandidate = DB::table('canonical_graph_projections')->where('id', $candidate->id)->first();

    $published = $service->markReady($candidate->id, 99, 88, canonicalProjectionMarker($candidate));

    $afterCurrent = DB::table('canonical_graph_projections')->where('id', $current->id)->first();
    $afterCandidate = DB::table('canonical_graph_projections')->where('id', $candidate->id)->first();
    expect($published)->toBeFalse()
        ->and($afterCurrent->status)->toBe('ready')
        ->and($afterCurrent->updated_at)->toBe($beforeCurrent->updated_at)
        ->and($afterCandidate->status)->toBe($candidateStatus)
        ->and($afterCandidate->node_count)->toBe($beforeCandidate->node_count)
        ->and($afterCandidate->relationship_count)->toBe($beforeCandidate->relationship_count)
        ->and($afterCandidate->projected_at)->toBe($beforeCandidate->projected_at)
        ->and($afterCandidate->updated_at)->toBe($beforeCandidate->updated_at);
})->with(['queued', 'failed', 'ready']);

function canonicalProjectionGraph(string $projectId, string $artifactId, string $checksum): array
{
    return [
        'identity' => [
            'project_id' => $projectId,
            'source_scope_type' => 'workspace_binding',
            'source_scope_id' => 'binding-1',
            'artifact_type' => 'hades_agent_artifact',
            'artifact_id' => $artifactId,
            'checksum' => $checksum,
        ],
        'contract' => [
            'extractor' => ['quality' => 'full'],
            'source' => ['head_commit' => 'abc123'],
        ],
    ];
}

function canonicalProjectionMarker(object $projection): Closure
{
    return fn () => app(Neo4jCanonicalGraphProjector::class)->publishCurrent($projection, new FakeNeo4jClient);
}
