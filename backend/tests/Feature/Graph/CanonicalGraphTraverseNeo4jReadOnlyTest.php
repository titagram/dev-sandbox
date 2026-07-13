<?php

use App\Services\Graph\CanonicalGraphQueryService;
use App\Services\Neo4jClientFactory;

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
