<?php

use App\Services\Graph\CanonicalGraphQueryService;
use App\Services\Graph\DashboardGraphExplorerService;
use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4jClientFactory;
use Illuminate\Support\Facades\DB;

it('accepts a configured live projection through search path neighborhood and impact without raw graph identity leaks', function (): void {
    $enabled = in_array(strtolower((string) getenv('NEO4J_GRAPH_ACCEPTANCE_ENABLED')), ['1', 'true', 'yes'], true);
    if (! $enabled) {
        $this->markTestSkipped('Set NEO4J_GRAPH_ACCEPTANCE_ENABLED=1 with the documented fixture variables to run the read-only explorer acceptance test.');
    }

    $environment = [
        'NEO4J_GRAPH_ACCEPTANCE_PROJECT_ID',
        'NEO4J_GRAPH_ACCEPTANCE_SCOPE_TYPE',
        'NEO4J_GRAPH_ACCEPTANCE_SCOPE_ID',
        'NEO4J_GRAPH_ACCEPTANCE_ACTIVE_GRAPH_VERSION',
        'NEO4J_GRAPH_ACCEPTANCE_FROM_HANDLE',
        'NEO4J_GRAPH_ACCEPTANCE_TO_HANDLE',
        'NEO4J_GRAPH_ACCEPTANCE_QUERY',
        'NEO4J_GRAPH_ACCEPTANCE_EXPECTED_SEARCH_HANDLE',
        'NEO4J_GRAPH_ACCEPTANCE_EXPECTED_NEIGHBOR_HANDLE',
        'NEO4J_GRAPH_ACCEPTANCE_EXPECTED_PATH_HANDLE',
        'NEO4J_GRAPH_ACCEPTANCE_EXPECTED_IMPACT_HANDLE',
    ];
    $values = array_map(static fn (string $key): string => trim((string) getenv($key)), $environment);
    $missing = array_values(array_filter($environment, static fn (string $key): bool => trim((string) getenv($key)) === ''));
    if ($missing !== []) {
        $this->fail('NEO4J_GRAPH_ACCEPTANCE_ENABLED=1 requires fixture configuration; missing: '.implode(', ', $missing));
    }

    [
        $projectId,
        $scopeType,
        $scopeId,
        $activeGraphVersion,
        $fromHandle,
        $toHandle,
        $query,
        $expectedSearchHandle,
        $expectedNeighborHandle,
        $expectedPathHandle,
        $expectedImpactHandle,
    ] = $values;

    $projection = DB::table('canonical_graph_projections')
        ->where('project_id', $projectId)
        ->where('source_scope_type', $scopeType)
        ->where('source_scope_id', $scopeId)
        ->where('status', 'ready')
        ->orderByDesc('projected_at')
        ->orderByDesc('id')
        ->first();
    expect($projection)->not->toBeNull()
        ->and((string) $projection->active_graph_version)->toBe($activeGraphVersion);

    $realClient = app(Neo4jClientFactory::class)->client();
    $client = new class($realClient) implements Neo4jClient {
        /** @var list<string> */
        public array $queries = [];

        public function __construct(private readonly Neo4jClient $delegate) {}

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->queries[] = $cypher;
            if (preg_match('/\b(?:CREATE|MERGE|SET|DELETE|DETACH|DROP|REMOVE|ALTER)\b/i', $cypher) === 1) {
                throw new RuntimeException('The live graph acceptance test is read-only; rejected a mutating Cypher query.');
            }

            return $this->delegate->run($cypher, $parameters);
        }
    };
    $service = new DashboardGraphExplorerService(new CanonicalGraphQueryService($client), $client);

    $responses = [
        'search' => $service->search($projectId, $scopeType, $scopeId, $query, 5),
        'path' => $service->path($projectId, $scopeType, $scopeId, $fromHandle, $toHandle, 4, 5),
        'neighborhood' => $service->neighborhood($projectId, $scopeType, $scopeId, $fromHandle, 'any', 2, 5),
        'impact' => $service->impact($projectId, $scopeType, $scopeId, $toHandle, 5),
    ];
    $allowedEdges = [
        'call' => ['CALLS', 'CALLS_METHOD', 'STATIC_CALL'],
        'dependency' => ['USES_DEPENDENCY', 'INSTANTIATES', 'EXTENDS', 'USES_FORM_REQUEST', 'THROWS_EXCEPTION', 'API_RESOURCE_REF'],
        'route' => ['ROUTE_HANDLER'],
        'test' => ['TEST_COVERS_SYMBOL', 'TEST_IMPORTS', 'TEST_COVERS_ROUTE'],
        'table' => ['QUERY_TABLE', 'ELOQUENT_QUERY'],
    ];
    $assertHandle = static function (mixed $handle): void {
        expect($handle)->toBeString()->toStartWith('gh1_');
    };
    $assertBounded = static function (array $response): void {
        expect($response['found'] ?? false)->toBeTrue()
            ->and($response['items'] ?? [])->not->toBeEmpty()
            ->and(count($response['items']))->toBeLessThanOrEqual((int) $response['limit'])
            ->and((int) ($response['returned'] ?? -1))->toBe(count($response['items']))
            ->and((bool) ($response['truncated'] ?? false) ? count($response['items']) === (int) $response['limit'] : true)->toBeTrue();
    };
    $assertEdges = static function (array $response, array $visibleHandles) use ($assertHandle, $allowedEdges): void {
        expect($response['edges'] ?? [])->not->toBeEmpty();
        foreach ($response['edges'] as $edge) {
            $assertHandle($edge['source_handle'] ?? null);
            $assertHandle($edge['target_handle'] ?? null);
            expect($visibleHandles)->toContain($edge['source_handle'])
                ->and($visibleHandles)->toContain($edge['target_handle'])
                ->and($allowedEdges[$edge['family'] ?? ''] ?? [])->toContain($edge['edge_type'] ?? '');
        }
    };

    $assertBounded($responses['search']);
    $assertHandle($expectedSearchHandle);
    expect(array_column($responses['search']['items'], 'handle'))->toContain($expectedSearchHandle);

    $assertBounded($responses['neighborhood']);
    $assertHandle($responses['neighborhood']['node']['handle'] ?? null);
    $assertHandle($expectedNeighborHandle);
    expect(array_column($responses['neighborhood']['items'], 'handle'))->toContain($expectedNeighborHandle);
    $neighborhoodVisible = array_merge(
        [(string) $responses['neighborhood']['node']['handle']],
        array_column($responses['neighborhood']['items'], 'handle'),
    );
    $assertEdges($responses['neighborhood'], $neighborhoodVisible);

    $assertBounded($responses['path']);
    $assertHandle($expectedPathHandle);
    expect(array_column($responses['path']['items'], 'handle'))->toContain($expectedPathHandle);
    $assertEdges($responses['path'], array_column($responses['path']['items'], 'handle'));

    $assertBounded($responses['impact']);
    $assertHandle($expectedImpactHandle);
    expect(array_column($responses['impact']['items'], 'handle'))->toContain($expectedImpactHandle);
    foreach ($responses['impact']['items'] as $item) {
        expect($allowedEdges[$item['family'] ?? ''] ?? [])->not->toBeEmpty();
    }

    foreach ($responses as $response) {
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        expect($json)
            ->not->toContain('source_id')
            ->not->toContain('target_id')
            ->not->toContain('/home/')
            ->not->toContain('/srv/')
            ->not->toContain('/data/private/')
            ->not->toContain('/Applications/')
            ->not->toContain('backend/app/')
            ->not->toContain('src/Foo.')
            ->not->toContain('../')
            ->not->toContain('./')
            ->not->toContain('C:workspace')
            ->not->toContain('file:///')
            ->not->toMatch('/(?:[A-Z]:[\\\\\/]|\\\\\\\\)/');
    }
    expect($client->queries)->not->toBeEmpty();
});
