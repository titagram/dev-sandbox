<?php

namespace App\Services\Graph;

use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4j\Neo4jResultMaterializer;
use App\Services\Neo4jClientFactory;
use Illuminate\Support\Facades\DB;

final class DashboardGraphExplorerService
{
    private const EDGE_FAMILIES = [
        'call' => ['CALLS', 'CALLS_METHOD', 'STATIC_CALL'],
        'dependency' => ['USES_DEPENDENCY', 'INSTANTIATES', 'EXTENDS', 'USES_FORM_REQUEST', 'THROWS_EXCEPTION', 'API_RESOURCE_REF'],
        'route' => ['ROUTE_HANDLER'],
        'test' => ['TEST_COVERS_SYMBOL', 'TEST_IMPORTS', 'TEST_COVERS_ROUTE'],
        'table' => ['QUERY_TABLE', 'ELOQUENT_QUERY'],
    ];

    private readonly DashboardGraphPublicHandle $publicHandles;
    private readonly DashboardGraphExplorerCursor $cursors;

    public function __construct(
        private readonly CanonicalGraphQueryService $canonicalQueries,
        private readonly ?Neo4jClient $client = null,
        ?DashboardGraphPublicHandle $publicHandles = null,
        ?DashboardGraphExplorerCursor $cursors = null,
    ) {
        $this->publicHandles = $publicHandles ?? new DashboardGraphPublicHandle;
        $this->cursors = $cursors ?? new DashboardGraphExplorerCursor;
    }

    /** @return array<string,mixed> */
    public function search(
        string $projectId,
        string $scopeType,
        string $scopeId,
        string $query,
        int $limit = 50,
        ?string $cursor = null,
    ): array {
        $normalisedQuery = $this->normaliseQuery($query);
        if ($normalisedQuery === '' || mb_strlen($normalisedQuery) > 160) {
            throw new \InvalidArgumentException('invalid_query');
        }

        $projection = DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', $scopeType)
            ->where('source_scope_id', $scopeId)
            ->where('status', 'ready')
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();
        if ($projection === null) {
            return ['found' => false, 'reason' => 'graph_projection_not_ready'];
        }

        $activeGraphVersion = (string) ($projection->active_graph_version ?? '');
        if ($activeGraphVersion === '') {
            return ['found' => false, 'reason' => 'graph_projection_rebuild_required'];
        }
        $boundedLimit = max(1, min(100, $limit));

        $cursorScore = null;
        $cursorHandle = null;
        if ($cursor !== null) {
            $payload = $this->cursors->decode($cursor);
            if ($payload['project_id'] !== $projectId
                || $payload['source_scope_type'] !== $scopeType
                || $payload['source_scope_id'] !== $scopeId
                || $payload['active_graph_version'] !== $activeGraphVersion
                || $payload['query_type'] !== 'search'
                || $payload['query'] !== $normalisedQuery) {
                throw new \InvalidArgumentException('invalid_cursor');
            }
            [$cursorScoreText, $cursorHandle] = explode('|', $payload['sort_key'], 2) + [null, null];
            if (! is_numeric($cursorScoreText) || ! is_string($cursorHandle) || ! $this->publicHandles->isWellFormed($cursorHandle)) {
                throw new \InvalidArgumentException('invalid_cursor');
            }
            $cursorScore = (float) $cursorScoreText;
        }

        $fullTextQuery = 'graph_version:"'.$this->luceneTerm($activeGraphVersion).'" AND ('
            .'public_search_name:'.$this->luceneTerm($normalisedQuery)
            .' OR public_search_label:'.$this->luceneTerm($normalisedQuery)
            .' OR public_search_path:'.$this->luceneTerm($normalisedQuery).')';
        $publicRows = [];
        $hasMore = false;
        $publicRowsHaveExtra = false;
        $scanScore = $cursorScore;
        $scanHandle = $cursorHandle;
        $scanned = 0;
        $scanCeiling = max(1000, $boundedLimit * 10);
        $lastProcessedScore = null;
        $lastProcessedHandle = null;
        while ($scanned < $scanCeiling) {
            $remaining = $scanCeiling - $scanned;
            $isFinalBatch = $remaining <= $boundedLimit + 1;
            $fetchLimit = $isFinalBatch
                ? $remaining + 1
                : min($boundedLimit + 1, $remaining);
            $cursorPredicate = $scanScore === null
                ? ''
                : ' AND (score < $cursor_score OR (score = $cursor_score AND node.public_handle > $cursor_handle))';
            $rows = $this->client()->run(
                'MATCH (version:CanonicalGraphVersion {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version}) '
                .'WHERE version.public_handle_key_version = $public_handle_key_version '
                .'AND version.public_handle_key_fingerprint = $public_handle_key_fingerprint '
                ."CALL db.index.fulltext.queryNodes('canonical_node_search', \$lucene_query) YIELD node, score "
                .'WHERE node.graph_version = $active_graph_version AND node.project_id = $project_id '
                .'AND node.source_scope_type = $source_scope_type AND node.source_scope_id = $source_scope_id '
                .$cursorPredicate.' '
                .'WITH node, score ORDER BY score DESC, node.public_handle ASC LIMIT $fetch_limit '
                .'RETURN properties(node) AS node, labels(node) AS labels, score',
                [
                    'project_id' => $projectId,
                    'source_scope_type' => $scopeType,
                    'source_scope_id' => $scopeId,
                    'active_graph_version' => $activeGraphVersion,
                    'lucene_query' => $fullTextQuery,
                    'fetch_limit' => $fetchLimit,
                    'public_handle_key_version' => $this->publicHandles->keyVersion(),
                    'public_handle_key_fingerprint' => $this->publicHandles->keyFingerprint(),
                    ...($scanScore === null ? [] : [
                        'cursor_score' => $scanScore,
                        'cursor_handle' => $scanHandle,
                    ]),
                ],
            );
            $rows = Neo4jResultMaterializer::materializeRows($rows);
            if ($scanScore !== null && $scanHandle !== null) {
                $rows = array_values(array_filter($rows, function (array $row) use ($scanScore, $scanHandle): bool {
                    $score = (float) ($row['score'] ?? 0);
                    $handle = (string) ($row['node']['public_handle'] ?? '');

                    return $score < $scanScore || ($score === $scanScore && strcmp($handle, $scanHandle) > 0);
                }));
            }
            usort($rows, function (array $left, array $right): int {
                $scoreComparison = (float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0);
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                return strcmp(
                    (string) (($left['node']['public_handle'] ?? '')),
                    (string) (($right['node']['public_handle'] ?? '')),
                );
            });
            if ($rows === []) {
                break;
            }

            $hasRawLookahead = $isFinalBatch && count($rows) > $remaining;
            $processedRows = $hasRawLookahead ? array_slice($rows, 0, $remaining) : $rows;
            $boundaryAdvanced = false;
            $scanned += count($processedRows);
            foreach ($processedRows as $row) {
                $rawHandle = $row['node']['public_handle'] ?? null;
                if (is_string($rawHandle) && $this->publicHandles->isWellFormed($rawHandle)) {
                    $lastProcessedScore = (float) ($row['score'] ?? 0);
                    $lastProcessedHandle = $rawHandle;
                    $boundaryAdvanced = true;
                }
                if (! is_array($row['node'] ?? null)) {
                    continue;
                }
                $item = $this->publicNode($row['node'], $row['score'] ?? null);
                if ($item !== null) {
                    $publicRows[] = ['item' => $item, 'row' => $row];
                }
            }
            if (! $boundaryAdvanced) {
                break;
            }
            $scanScore = $lastProcessedScore;
            $scanHandle = $lastProcessedHandle;
            if (count($publicRows) > $boundedLimit) {
                $hasMore = true;
                $publicRowsHaveExtra = true;
                break;
            }
            if ($hasRawLookahead) {
                $hasMore = true;
                break;
            }
            if (count($rows) < $fetchLimit) {
                break;
            }
        }
        $pageRows = array_slice($publicRows, 0, $boundedLimit);
        $items = array_column($pageRows, 'item');
        $nextCursor = null;
        if ($hasMore) {
            $last = $publicRowsHaveExtra ? ($items[array_key_last($items)] ?? null) : null;
            $nextScore = is_array($last) && is_numeric($last['score'] ?? null)
                ? (float) $last['score']
                : $lastProcessedScore;
            $nextHandle = is_array($last) && is_string($last['handle'] ?? null)
                ? $last['handle']
                : $lastProcessedHandle;
            if ($nextScore === null || ! is_string($nextHandle) || ! $this->publicHandles->isWellFormed($nextHandle)) {
                $hasMore = false;
            } else {
                $nextCursor = $this->cursors->encode(
                    $projectId,
                    $scopeType,
                    $scopeId,
                    $activeGraphVersion,
                    'search',
                    $normalisedQuery,
                    sprintf('%.17g|%s', $nextScore, $nextHandle),
                );
            }
        }

        return [
            'found' => true,
            'reason' => null,
            'items' => $items,
            'edges' => [],
            'returned' => count($items),
            'limit' => $boundedLimit,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /** @return array<string,mixed> */
    public function overview(string $projectId, string $scopeType, string $scopeId): array
    {
        $projection = $this->readyProjection($projectId, $scopeType, $scopeId);
        if ($projection === null) {
            return ['found' => false, 'reason' => 'graph_projection_not_ready'];
        }

        $activeGraphVersion = (string) ($projection->active_graph_version ?? '');
        if ($activeGraphVersion === '') {
            return ['found' => false, 'reason' => 'graph_projection_rebuild_required'];
        }
        if (! $this->projectionKeyIsCurrent($projectId, $scopeType, $scopeId, $activeGraphVersion)) {
            return ['found' => false, 'reason' => 'graph_projection_rebuild_required'];
        }

        return [
            'found' => true,
            'reason' => null,
            'project_id' => $projectId,
            'scope' => ['type' => $scopeType, 'id' => $scopeId],
            'projection' => [
                'status' => (string) $projection->status,
                'quality' => $projection->quality,
                'generated_at' => $projection->projected_at,
                'active_graph_version' => $activeGraphVersion,
                'node_count' => (int) ($projection->node_count ?? 0),
                'relationship_count' => (int) ($projection->relationship_count ?? 0),
                'unknown_kind_count' => 0,
                'missing_label_count' => 0,
                'excluded_node_count' => 0,
            ],
            'source' => ['type' => 'canonical', 'verified' => true],
        ];
    }

    /** @return array<string,mixed> */
    public function scopes(string $projectId, int $limit = 50, ?string $cursor = null): array
    {
        $boundedLimit = max(1, min(100, $limit));
        $activeGraphVersion = $this->scopeCursorVersion($projectId);
        $normalisedQuery = '';
        $cursorScopeType = null;
        $cursorScopeId = null;
        if ($cursor !== null) {
            $payload = $this->cursors->decode($cursor);
            if ($payload['project_id'] !== $projectId
                || $payload['source_scope_type'] !== null
                || $payload['source_scope_id'] !== null
                || $payload['active_graph_version'] !== $activeGraphVersion
                || $payload['query_type'] !== 'scopes'
                || $payload['query'] !== $normalisedQuery) {
                throw new \InvalidArgumentException('invalid_cursor');
            }
            if (substr_count($payload['sort_key'], '|') !== 1) {
                throw new \InvalidArgumentException('invalid_cursor');
            }
            [$cursorScopeType, $cursorScopeId] = explode('|', $payload['sort_key'], 2);
        }

        $query = $this->latestReadyScopeQuery($projectId)
            ->orderBy('source_scope_type')
            ->orderBy('source_scope_id')
            ->limit($boundedLimit + 1);
        if ($cursorScopeType !== null && $cursorScopeId !== null) {
            $query->where(function ($query) use ($cursorScopeType, $cursorScopeId): void {
                $query->where('source_scope_type', '>', $cursorScopeType)
                    ->orWhere(function ($query) use ($cursorScopeType, $cursorScopeId): void {
                        $query->where('source_scope_type', $cursorScopeType)
                            ->where('source_scope_id', '>', $cursorScopeId);
                    });
            });
        }
        $projections = $query->get()->all();
        if ($projections === []) {
            return ['found' => false, 'reason' => 'graph_scope_not_found'];
        }

        $hasMore = count($projections) > $boundedLimit;
        $page = array_slice($projections, 0, $boundedLimit);
        $items = array_map(static fn (object $projection): array => [
            'source_scope_type' => (string) $projection->source_scope_type,
            'source_scope_id' => (string) $projection->source_scope_id,
            'active_graph_version' => (string) $projection->active_graph_version,
            'status' => (string) $projection->status,
            'quality' => $projection->quality,
            'node_count' => (int) ($projection->node_count ?? 0),
            'relationship_count' => (int) ($projection->relationship_count ?? 0),
        ], $page);
        $nextCursor = null;
        if ($hasMore && $items !== []) {
            $last = $items[array_key_last($items)];
            $nextCursor = $this->cursors->encode(
                $projectId,
                null,
                null,
                $activeGraphVersion,
                'scopes',
                $normalisedQuery,
                $last['source_scope_type'].'|'.$last['source_scope_id'],
            );
        }

        return [
            'found' => true,
            'reason' => null,
            'project_id' => $projectId,
            'items' => $items,
            'edges' => [],
            'returned' => count($items),
            'limit' => $boundedLimit,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /** @return array<string,mixed> */
    public function neighborhood(
        string $projectId,
        string $scopeType,
        string $scopeId,
        string $handle,
        string $direction,
        int $maxDepth,
        int $limit,
        array $families = [],
    ): array {
        if (! in_array($direction, ['in', 'out', 'any'], true)) {
            throw new \InvalidArgumentException('invalid_direction');
        }
        $this->assertFamilies($families);

        $resolved = $this->resolveNode($projectId, $scopeType, $scopeId, $handle);
        if ($resolved === null) {
            return ['found' => false, 'reason' => 'node_not_found'];
        }
        $boundedLimit = max(1, min(50, $limit));

        $result = $this->canonicalQueries->query(
            $projectId,
            $scopeType,
            $scopeId,
            'traverse',
            [
                'start_external_id' => (string) $resolved['node']['external_id'],
                'start' => '',
                'direction' => $direction,
                'max_depth' => max(1, min(3, $maxDepth)),
                'limit' => min(51, $boundedLimit + 1),
                'families' => $families,
            ],
        );
        if (($result['found'] ?? false) !== true) {
            return ['found' => false, 'reason' => $result['reason'] ?? 'query_error'];
        }

        $items = array_values(array_filter(array_map(
            fn (mixed $node): ?array => is_array($node)
                && (string) ($node['id'] ?? '') !== (string) $resolved['node']['external_id']
                ? $this->publicCanonicalNode($node)
                : null,
            is_array($result['results'] ?? null) ? $result['results'] : [],
        )));
        $truncated = (bool) ($result['truncated'] ?? false) || count($items) > $boundedLimit;
        $items = array_slice($items, 0, $boundedLimit);
        $root = $this->publicNode($resolved['node']);
        $visibleHandles = array_fill_keys(array_filter([
            is_array($root) ? ($root['handle'] ?? null) : null,
            ...array_column($items, 'handle'),
]), true);
        $edges = array_values(array_filter(array_map(
            fn (mixed $edge): ?array => is_array($edge) ? $this->publicCanonicalEdge($edge) : null,
            is_array($result['edges'] ?? null) ? $result['edges'] : [],
        )));
        $edges = array_values(array_filter($edges, static fn (array $edge): bool => isset(
            $visibleHandles[$edge['source_handle'] ?? ''],
            $visibleHandles[$edge['target_handle'] ?? ''],
        )));

        return [
            'found' => true,
            'reason' => null,
            'node' => $this->publicNode($resolved['node']),
            'items' => $items,
            'edges' => $edges,
            'returned' => count($items),
            'limit' => $boundedLimit,
            'truncated' => $truncated,
        ];
    }

    /** @return array<string,mixed> */
    public function path(
        string $projectId,
        string $scopeType,
        string $scopeId,
        string $fromHandle,
        string $toHandle,
        int $maxDepth,
        int $limit,
    ): array {
        $from = $this->resolveNode($projectId, $scopeType, $scopeId, $fromHandle);
        $to = $this->resolveNode($projectId, $scopeType, $scopeId, $toHandle);
        if ($from === null || $to === null) {
            return ['found' => false, 'reason' => 'node_not_found'];
        }

        $result = $this->canonicalQueries->query(
            $projectId,
            $scopeType,
            $scopeId,
            'path',
            [
                'from_symbol_id' => (string) $from['node']['external_id'],
                'to_symbol_id' => (string) $to['node']['external_id'],
                'max_depth' => max(1, min(10, $maxDepth)),
                'limit' => max(1, min(50, $limit)),
            ],
        );
        if (($result['found'] ?? false) !== true) {
            return ['found' => false, 'reason' => $result['reason'] ?? 'path_not_found'];
        }
        $boundedLimit = max(1, min(50, $limit));

        $items = array_values(array_filter(array_map(
            fn (mixed $node): ?array => is_array($node) ? $this->publicCanonicalNode($node) : null,
            is_array($result['results'] ?? null) ? $result['results'] : [],
        )));
        $truncated = (bool) ($result['truncated'] ?? false) || count($items) > $boundedLimit;
        $items = array_slice($items, 0, $boundedLimit);
        $edges = array_values(array_filter(array_map(
            fn (mixed $edge): ?array => is_array($edge) ? $this->publicCanonicalEdge($edge) : null,
            is_array($result['edges'] ?? null) ? $result['edges'] : [],
        )));
        $visibleHandles = array_fill_keys(array_column($items, 'handle'), true);
        $edges = array_values(array_filter($edges, static fn (array $edge): bool => isset(
            $visibleHandles[$edge['source_handle'] ?? ''],
            $visibleHandles[$edge['target_handle'] ?? ''],
        )));

        return [
            'found' => true,
            'reason' => null,
            'items' => $items,
            'edges' => $edges,
            'returned' => count($items),
            'limit' => $boundedLimit,
            'truncated' => $truncated,
        ];
    }

    /** @return array<string,mixed> */
    public function impact(string $projectId, string $scopeType, string $scopeId, string $handle, int $limit = 50): array
    {
        $resolved = $this->resolveNode($projectId, $scopeType, $scopeId, $handle);
        if ($resolved === null) {
            return ['found' => false, 'reason' => 'node_not_found'];
        }
        $projection = $resolved['projection'];
        $activeGraphVersion = (string) ($projection->active_graph_version ?? '');
        if ($activeGraphVersion === '') {
            return ['found' => false, 'reason' => 'graph_projection_rebuild_required'];
        }
        $boundedLimit = max(1, min(50, $limit));
        $fetchLimit = $boundedLimit + 1;

        $edgeTypes = array_values(array_unique(array_merge(...array_values(self::EDGE_FAMILIES))));
        $edgePredicate = implode(', ', array_map(static fn (string $type): string => "'{$type}'", $edgeTypes));
        $familyCase = $this->impactFamilyCase('edge_type');
        $rows = $this->client()->run(
            'MATCH (version:CanonicalGraphVersion {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version}) '
            .'MATCH (target:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version, public_handle: $public_handle}) '
            .'MATCH path = (affected:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version})-[*1..2]->(target) '
            .'WHERE version.public_handle_key_version = $public_handle_key_version '
            .'AND version.public_handle_key_fingerprint = $public_handle_key_fingerprint '
            .'AND ALL(n IN nodes(path) WHERE n.project_id = $project_id AND n.source_scope_type = $source_scope_type AND n.source_scope_id = $source_scope_id AND n.graph_version = $active_graph_version) '
            .'AND ALL(r IN relationships(path) WHERE type(r) IN ['.$edgePredicate.']) '
            .'WITH affected, length(path) AS distance, [r IN relationships(path) | type(r)] AS edge_types '
            .'UNWIND edge_types AS edge_type '
            .'WITH affected, distance, edge_type, '.$familyCase.' AS family '
            .'WITH affected, family, min(distance) AS distance, collect(DISTINCT edge_type) AS edge_types '
            .'ORDER BY distance, affected.public_handle ASC, family ASC LIMIT $fetch_limit '
            .'RETURN properties(affected) AS node, labels(affected) AS labels, distance, family, edge_types',
            [
                'project_id' => $projectId,
                'source_scope_type' => $scopeType,
                'source_scope_id' => $scopeId,
                'active_graph_version' => $activeGraphVersion,
                'public_handle' => $handle,
                'public_handle_key_version' => $this->publicHandles->keyVersion(),
                'public_handle_key_fingerprint' => $this->publicHandles->keyFingerprint(),
                'fetch_limit' => $fetchLimit,
            ],
        );

        $groups = [];
        $rows = Neo4jResultMaterializer::materializeRows($rows);
        foreach ($rows as $row) {
            if (! is_array($row['node'] ?? null)) {
                continue;
            }
            $node = $this->publicNode($row['node']);
            if ($node === null) {
                continue;
            }
            $edgeTypesForRow = array_values(array_filter(
                is_array($row['edge_types'] ?? null) ? array_map('strval', $row['edge_types']) : [],
                fn (string $type): bool => $this->edgeFamily($type) !== 'other',
            ));
            $edgeTypesForRow = array_values(array_unique($edgeTypesForRow));
            sort($edgeTypesForRow, SORT_STRING);
            $families = [];
            if (is_string($row['family'] ?? null) && ($row['family'] ?? '') !== 'other') {
                $families[(string) $row['family']] = $edgeTypesForRow;
            } else {
                foreach ($edgeTypesForRow as $edgeType) {
                    $families[$this->edgeFamily($edgeType)][] = $edgeType;
                }
            }
            foreach ($families as $family => $familyEdgeTypes) {
                $familyEdgeTypes = array_values(array_unique(array_map('strval', $familyEdgeTypes)));
                sort($familyEdgeTypes, SORT_STRING);
                $key = $node['handle'].'|'.$node['kind'].'|'.$family;
                $groups[$key] ??= [
                    'handle' => $node['handle'],
                    'kind' => $node['kind'],
                    'label' => $node['label'],
                    'distance' => (int) ($row['distance'] ?? 0),
                    'family' => $family,
                    'edge_types' => [],
                    'why' => null,
                ];
                $groups[$key]['distance'] = min($groups[$key]['distance'], (int) ($row['distance'] ?? 0));
                array_push($groups[$key]['edge_types'], ...$familyEdgeTypes);
                $groups[$key]['edge_types'] = array_values(array_unique($groups[$key]['edge_types']));
                $groups[$key]['why'] = $family.' edge '.implode(',', $groups[$key]['edge_types']);
            }
        }
        $items = array_map(static function (array $item): array {
            sort($item['edge_types'], SORT_STRING);
            $item['why'] = $item['family'].' edge '.implode(',', $item['edge_types']);

            return $item;
        }, array_values($groups));
        usort($items, static fn (array $left, array $right): int => [$left['distance'], $left['handle'], $left['family']] <=> [$right['distance'], $right['handle'], $right['family']]);
        $truncated = count($items) > $boundedLimit;
        $items = array_slice($items, 0, $boundedLimit);

        return [
            'found' => true,
            'reason' => null,
            'items' => $items,
            'edges' => [],
            'returned' => count($items),
            'limit' => $boundedLimit,
            'truncated' => $truncated,
        ];
    }

    /** @return array<string,mixed> */
    public function detail(string $projectId, string $scopeType, string $scopeId, string $handle, int $limit = 50): array
    {
        $projection = DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', $scopeType)
            ->where('source_scope_id', $scopeId)
            ->where('status', 'ready')
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();

        if ($projection === null) {
            return ['found' => false, 'reason' => 'graph_projection_not_ready'];
        }

        $activeGraphVersion = (string) ($projection->active_graph_version ?? '');
        if ($activeGraphVersion === '') {
            return ['found' => false, 'reason' => 'graph_projection_rebuild_required'];
        }
        if (! $this->publicHandles->isWellFormed($handle)) {
            return ['found' => false, 'reason' => 'node_not_found'];
        }

        $rows = $this->client()->run(
            'MATCH (version:CanonicalGraphVersion {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version}) '
            .'MATCH (node:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version, public_handle: $public_handle}) '
            .'WHERE version.public_handle_key_version = $public_handle_key_version '
            .'AND version.public_handle_key_fingerprint = $public_handle_key_fingerprint '
            .'RETURN properties(node) AS node, labels(node) AS labels LIMIT 1',
            $this->nodeLookupParameters($projectId, $scopeType, $scopeId, $activeGraphVersion, $handle),
        );
        $row = Neo4jResultMaterializer::materializeRows($rows)[0] ?? null;
        if (! is_array($row) || ! is_array($row['node'] ?? null)) {
            return ['found' => false, 'reason' => 'node_not_found'];
        }

        $node = $row['node'];

        return [
            'found' => true,
            'reason' => null,
            'node' => [
                'handle' => (string) ($node['public_handle'] ?? $handle),
                'kind' => $this->publicKind($node['kind'] ?? null),
                'label' => is_string($node['public_search_label'] ?? null)
                    ? $this->safePublicSearchValue($node['public_search_label'])
                    : null,
            ],
            'items' => [],
            'edges' => [],
            'limit' => max(1, $limit),
        ];
    }

    private function client(): Neo4jClient
    {
        return $this->client ?? app(Neo4jClientFactory::class)->client();
    }

    private function projectionKeyIsCurrent(
        string $projectId,
        string $scopeType,
        string $scopeId,
        string $activeGraphVersion,
    ): bool {
        try {
            $rows = $this->client()->run(
                'MATCH (version:CanonicalGraphVersion {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version}) '
                .'WHERE version.public_handle_key_version = $public_handle_key_version '
                .'AND version.public_handle_key_fingerprint = $public_handle_key_fingerprint '
                .'RETURN version.public_handle_key_version AS public_handle_key_version, '
                .'version.public_handle_key_fingerprint AS public_handle_key_fingerprint LIMIT 1',
                [
                    'project_id' => $projectId,
                    'source_scope_type' => $scopeType,
                    'source_scope_id' => $scopeId,
                    'active_graph_version' => $activeGraphVersion,
                    'public_handle_key_version' => $this->publicHandles->keyVersion(),
                    'public_handle_key_fingerprint' => $this->publicHandles->keyFingerprint(),
                ],
            );
        } catch (\Throwable) {
            return false;
        }
        foreach (Neo4jResultMaterializer::materializeRows($rows) as $row) {
            return $this->neo4jRowValue($row, 'public_handle_key_version') === $this->publicHandles->keyVersion()
                && $this->neo4jRowValue($row, 'public_handle_key_fingerprint') === $this->publicHandles->keyFingerprint();
        }

        return false;
    }

    private function neo4jRowValue(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        if ($row instanceof \ArrayAccess && $row->offsetExists($key)) {
            return $row[$key];
        }

        if (is_object($row) && method_exists($row, 'toArray')) {
            $values = $row->toArray();

            return is_array($values) ? ($values[$key] ?? null) : null;
        }

        return is_object($row) && property_exists($row, $key) ? $row->{$key} : null;
    }

    /** @return array<string, mixed> */
    private function nodeLookupParameters(
        string $projectId,
        string $scopeType,
        string $scopeId,
        string $activeGraphVersion,
        string $handle,
    ): array {
        return [
            'project_id' => $projectId,
            'source_scope_type' => $scopeType,
            'source_scope_id' => $scopeId,
            'active_graph_version' => $activeGraphVersion,
            'public_handle' => $handle,
            'public_handle_key_version' => $this->publicHandles->keyVersion(),
            'public_handle_key_fingerprint' => $this->publicHandles->keyFingerprint(),
        ];
    }

    /** @return array{projection:object,node:array<string,mixed>}|null */
    private function resolveNode(string $projectId, string $scopeType, string $scopeId, string $handle): ?array
    {
        $projection = $this->readyProjection($projectId, $scopeType, $scopeId);
        if ($projection === null || ! $this->publicHandles->isWellFormed($handle)) {
            return null;
        }
        $activeGraphVersion = (string) ($projection->active_graph_version ?? '');
        if ($activeGraphVersion === '') {
            return null;
        }
        $rows = $this->client()->run(
            'MATCH (version:CanonicalGraphVersion {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version}) '
            .'MATCH (node:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version, public_handle: $public_handle}) '
            .'WHERE version.public_handle_key_version = $public_handle_key_version '
            .'AND version.public_handle_key_fingerprint = $public_handle_key_fingerprint '
            .'RETURN properties(node) AS node, labels(node) AS labels LIMIT 1',
            $this->nodeLookupParameters($projectId, $scopeType, $scopeId, $activeGraphVersion, $handle),
        );
        $row = Neo4jResultMaterializer::materializeRows($rows)[0] ?? null;

        return is_array($row) && is_array($row['node'] ?? null)
            ? ['projection' => $projection, 'node' => $row['node']]
            : null;
    }

    /** @param array<string,mixed> $node */
    private function publicCanonicalNode(array $node): ?array
    {
        $handle = (string) ($node['handle'] ?? '');
        if (! $this->publicHandles->isWellFormed($handle)) {
            return null;
        }
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        return [
            'handle' => $handle,
            'kind' => $this->publicKind($node['kind'] ?? null),
            'label' => is_string($properties['public_search_label'] ?? null)
                ? $this->safePublicSearchValue($properties['public_search_label'])
                : null,
        ];
    }

    /** @param array<string,mixed> $edge */
    private function publicCanonicalEdge(array $edge): ?array
    {
        if (! $this->publicHandles->isWellFormed((string) ($edge['source_handle'] ?? ''))
            || ! $this->publicHandles->isWellFormed((string) ($edge['target_handle'] ?? ''))) {
            return null;
        }

        return [
            'source_handle' => $edge['source_handle'],
            'target_handle' => $edge['target_handle'],
            'edge_type' => (string) ($edge['edge_type'] ?? $edge['type'] ?? 'RELATED'),
            'family' => (string) ($edge['family'] ?? 'other'),
        ];
    }

    private function impactFamilyCase(string $edgeTypeAlias): string
    {
        $cases = [];
        foreach (self::EDGE_FAMILIES as $family => $types) {
            $quotedTypes = implode(', ', array_map(static fn (string $type): string => "'{$type}'", $types));
            $cases[] = "WHEN {$edgeTypeAlias} IN [{$quotedTypes}] THEN '{$family}'";
        }

        return 'CASE '.implode(' ', $cases)." ELSE 'other' END";
    }

    private function edgeFamily(string $edgeType): string
    {
        foreach (self::EDGE_FAMILIES as $family => $types) {
            if (in_array(strtoupper($edgeType), $types, true)) {
                return $family;
            }
        }

        return 'other';
    }

    private function readyProjection(string $projectId, string $scopeType, string $scopeId): ?object
    {
        return DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', $scopeType)
            ->where('source_scope_id', $scopeId)
            ->where('status', 'ready')
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();
    }

    private function latestReadyScopeQuery(string $projectId): \Illuminate\Database\Query\Builder
    {
        $ranked = DB::table('canonical_graph_projections')
            ->select([
                'id',
                'source_scope_type',
                'source_scope_id',
                'active_graph_version',
                'status',
                'quality',
                'node_count',
                'relationship_count',
                'projected_at',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY source_scope_type, source_scope_id ORDER BY projected_at DESC, id DESC) AS scope_rank'),
            ])
            ->where('project_id', $projectId)
            ->where('status', 'ready');

        return DB::query()
            ->fromSub($ranked, 'latest_ready_scopes')
            ->where('scope_rank', 1);
    }

    private function scopeCursorVersion(string $projectId): string
    {
        $summary = $this->latestReadyScopeQuery($projectId)
            ->selectRaw('COUNT(*) AS scope_count, MAX(projected_at) AS latest_projected_at, MAX(id) AS latest_id')
            ->first();

        return hash('sha256', implode('|', [
            $projectId,
            (string) ($summary->scope_count ?? 0),
            (string) ($summary->latest_projected_at ?? ''),
            (string) ($summary->latest_id ?? ''),
        ]));
    }

    private function normaliseQuery(string $query): string
    {
        if (preg_match('//u', $query) !== 1) {
            return '';
        }

        $normalised = preg_replace('/\s+/u', ' ', $query);

        return is_string($normalised) ? trim($normalised) : '';
    }

    private function luceneTerm(string $value): string
    {
        $escaped = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($character === ' ' || str_contains('+-!(){}[]^"~*?:\\/&|<>', $character)) {
                $escaped .= '\\';
            }
            $escaped .= $character;
        }

        return $escaped;
    }

    /** @param array<string,mixed> $node */
    private function publicNode(array $node, mixed $score = null): ?array
    {
        $handle = (string) ($node['public_handle'] ?? '');
        if (! $this->publicHandles->isWellFormed($handle)) {
            return null;
        }

        $label = null;
        $hasStringLabel = false;
        foreach ([$node['public_search_label'] ?? null, $node['public_search_name'] ?? null] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $hasStringLabel = true;
            $label = $this->safePublicSearchValue($candidate);
            if ($label !== null) {
                break;
            }
        }
        if ($hasStringLabel && $label === null) {
            return null;
        }

        $item = [
            'handle' => $handle,
            'kind' => $this->publicKind($node['kind'] ?? null),
            'label' => $label,
        ];
        if (is_numeric($score)) {
            $item['score'] = (float) $score;
        }

        return $item;
    }

    private function publicKind(mixed $value): string
    {
        if (! is_string($value)) {
            return 'unknown';
        }

        return match (strtolower(trim($value))) {
            'route', 'http_endpoint', 'httpendpoint', 'endpoint' => 'route',
            'file' => 'file',
            'module', 'namespace', 'package' => 'module',
            'class', 'interface', 'trait', 'enum' => 'class',
            'function' => 'function',
            'method' => 'method',
            'model' => 'model',
            'service' => 'service',
            default => 'unknown',
        };
    }

    private function safePublicSearchValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $this->isTechnicalLegacyValue($value)) {
            return null;
        }

        return $value;
    }

    private function isTechnicalLegacyValue(string $value): bool
    {
        return preg_match('/\A(?:hades-public-|legacy[-_:]|(?:node|edge|internal)[-_:])/i', $value) === 1;
    }

    private function assertFamilies(array $families): void
    {
        if (! array_is_list($families)) {
            throw new \InvalidArgumentException('invalid_family');
        }

        foreach ($families as $family) {
            if (! is_string($family) || ! isset(self::EDGE_FAMILIES[$family])) {
                throw new \InvalidArgumentException('invalid_family');
            }
        }
    }
}
