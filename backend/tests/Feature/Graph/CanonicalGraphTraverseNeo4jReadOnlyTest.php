<?php

use App\Services\Graph\CanonicalGraphQueryService;
use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4jClientFactory;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DevBoardSeeder::class);
});

it('uses the published active version and exact semantic edge families for callers and callees', function (): void {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $agentId = (string) Str::ulid();
    $scopeId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $now = now();
    DB::table('hades_agents')->insert([
        'id' => $agentId,
        'project_id' => $projectId,
        'external_agent_id' => 'graph-test-'.$agentId,
        'label' => 'Graph test agent',
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
        'external_agent_id' => 'graph-test-'.$agentId,
        'workspace_fingerprint' => hash('sha256', $scopeId),
        'display_path' => '/srv/private/graph-test',
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
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => $scopeId,
        'artifact_type' => 'hades_agent_artifact',
        'artifact_id' => $artifactId,
        'graph_version' => 'origin-v1',
        'active_graph_version' => 'published-v2',
        'checksum' => hash('sha256', 'graph-test'),
        'head_commit' => str_repeat('a', 40),
        'quality' => 'full',
        'status' => 'ready',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $client = new class implements Neo4jClient
    {
        /** @var list<array{cypher:string, params:array<string,mixed>}> */
        public array $commands = [];

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->commands[] = ['cypher' => $cypher, 'params' => $parameters];

            return [[
                'node' => [
                    'external_id' => 'method:Caller',
                    'public_handle' => 'gh1_caller',
                    'kind' => 'method',
                    'name' => 'Caller',
                ],
                'labels' => ['Symbol', 'Method'],
                'edge' => [
                    'external_id' => 'edge-1',
                    'type' => 'CALLS_METHOD',
                    'source_id' => 'method:Caller',
                    'target_id' => 'method:Target',
                ],
            ]];
        }
    };

    $service = new CanonicalGraphQueryService($client);
    $callers = $service->query($projectId, 'workspace_binding', $scopeId, 'callers', [
        'symbol_id' => 'method:Target',
        'limit' => 10,
    ]);
    $callees = $service->query($projectId, 'workspace_binding', $scopeId, 'callees', [
        'symbol_id' => 'method:Caller',
        'limit' => 10,
    ]);

    expect($callers['found'])->toBeTrue()
        ->and($callees['found'])->toBeTrue()
        ->and($client->commands)->toHaveCount(2)
        ->and($client->commands[0]['params']['graph_version'])->toBe('published-v2')
        ->and($client->commands[0]['params']['source_scope_type'])->toBe('workspace_binding')
        ->and($client->commands[0]['params']['source_scope_id'])->toBe($scopeId)
        ->and($client->commands[0]['cypher'])->toContain('CALLS_METHOD')
        ->and($client->commands[0]['cypher'])->toContain('USES_DEPENDENCY')
        ->and($client->commands[0]['cypher'])->not->toContain('ROUTES_TO')
        ->and($client->commands[0]['cypher'])->not->toContain('READS_TABLE');
});

it('executes canonical traversal against Neo4j without writes', function () {
    $graphVersion = (string) getenv('NEO4J_READ_ONLY_SMOKE_GRAPH_VERSION');
    $start = (string) getenv('NEO4J_READ_ONLY_SMOKE_START');

    if ($graphVersion === '' || $start === '') {
        $this->markTestSkipped('Set NEO4J_READ_ONLY_SMOKE_GRAPH_VERSION and NEO4J_READ_ONLY_SMOKE_START to run this read-only smoke test.');
    }

    $limit = 5;
    $maxDepth = 2;
    $service = new CanonicalGraphQueryService;
    $reflection = new ReflectionClass($service);
    $runTraverse = $reflection->getMethod('runTraverse');
    $normaliseRows = $reflection->getMethod('normaliseRows');

    $rows = $runTraverse->invoke(
        $service,
        app(Neo4jClientFactory::class)->client(),
        ['start' => $start, 'direction' => 'any', 'max_depth' => $maxDepth, 'limit' => $limit],
        (object) ['graph_version' => $graphVersion],
    );
    [$nodes, $edges, $truncated] = $normaliseRows->invoke($service, $rows, $limit);

    expect($rows)->toBeIterable()
        ->and($nodes)->not->toBeEmpty()
        ->and(array_column($nodes, 'id'))->toContain($start)
        ->and(count($nodes))->toBeLessThanOrEqual($limit)
        ->and($edges)->not->toBeEmpty()
        ->and(count($edges))->toBeLessThanOrEqual(min(200, max($limit + 1, $limit * $maxDepth)) * $maxDepth)
        ->and($truncated)->toBeTrue();

    $returnedNodeIds = array_fill_keys(array_column($nodes, 'id'), true);
    foreach ($edges as $edge) {
        expect($edge)->toHaveKeys(['source_id', 'target_id'])
            ->and($returnedNodeIds)->toHaveKey((string) $edge['source_id'])
            ->and($returnedNodeIds)->toHaveKey((string) $edge['target_id']);
    }
})->group('neo4j-read-only');

it('returns a matching isolated start from Neo4j without relationships', function () {
    $graphVersion = (string) getenv('NEO4J_READ_ONLY_SMOKE_GRAPH_VERSION');
    $start = (string) getenv('NEO4J_READ_ONLY_SMOKE_ISOLATED_START');

    if ($graphVersion === '' || $start === '') {
        $this->markTestSkipped('Set NEO4J_READ_ONLY_SMOKE_GRAPH_VERSION and NEO4J_READ_ONLY_SMOKE_ISOLATED_START to run this read-only smoke test.');
    }

    $service = new CanonicalGraphQueryService;
    $reflection = new ReflectionClass($service);
    $runTraverse = $reflection->getMethod('runTraverse');
    $normaliseRows = $reflection->getMethod('normaliseRows');

    $rows = $runTraverse->invoke(
        $service,
        app(Neo4jClientFactory::class)->client(),
        ['start' => $start, 'direction' => 'any', 'max_depth' => 2, 'limit' => 5],
        (object) ['graph_version' => $graphVersion],
    );
    [$nodes, $edges, $truncated] = $normaliseRows->invoke($service, $rows, 5);

    expect(array_column($nodes, 'id'))->toBe([$start])
        ->and($edges)->toBe([])
        ->and($truncated)->toBeFalse();
})->group('neo4j-read-only');
