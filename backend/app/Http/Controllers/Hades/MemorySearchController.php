<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesProjectAwareness;
use App\Services\Hades\HadesSearchDocumentIndexer;
use App\Services\Search\EmbeddingIndexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MemorySearchController extends Controller
{
    private const RAW_CHUNK_MARKERS = [
        'file_chunk',
        'source_chunk',
        'backend_wiki.file_chunk',
        '---begin_content---',
    ];

    public function __construct(
        private readonly HadesProjectAwareness $awareness,
        private readonly HadesSearchDocumentIndexer $searchIndexer,
        private readonly EmbeddingIndexService $embeddingIndex,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'query' => ['nullable', 'string', 'max:1000'],
            'domain' => ['nullable', 'string', 'in:all,project_memory,logbook,wiki,agent_notes,source_chunks,artifacts'],
            'kind' => ['nullable', 'string', 'max:120'],
            'schema' => ['nullable', 'string', 'max:160'],
            'source' => ['nullable', 'string', 'max:512'],
            'symbol' => ['nullable', 'string', 'max:512'],
            'path' => ['nullable', 'string', 'max:1024'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'include_raw_chunks' => ['nullable', 'boolean'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $query = trim((string) ($validated['query'] ?? ''));
        $domain = (string) ($validated['domain'] ?? 'all');
        $limit = (int) ($validated['limit'] ?? 10);
        $includeRawChunks = (bool) ($validated['include_raw_chunks'] ?? false);
        $filters = $this->filters($validated);
        $rawChunksOmitted = 0;
        $items = [];

        if (! in_array($domain, ['wiki', 'artifacts'], true)) {
            [$memoryItems, $omitted] = $this->memoryResults(
                projectId: $validated['project_id'],
                query: $query,
                domain: $domain,
                filters: $filters,
                workspaceBindingId: $binding->id,
                limit: $limit,
                includeRawChunks: $includeRawChunks,
                workspaceHeadCommit: (string) ($binding->head_commit ?? ''),
            );
            $items = array_merge($items, $memoryItems);
            $rawChunksOmitted += $omitted;
        }

        if (in_array($domain, ['all', 'wiki'], true)) {
            $items = array_merge($items, $this->wikiResults($validated['project_id'], $query, $filters, $limit));
        }

        if (in_array($domain, ['all', 'artifacts'], true)) {
            $items = array_merge($items, $this->artifactResults($validated['project_id'], $binding->id, $query, $filters, $limit));
        }

        usort($items, function (array $a, array $b): int {
            $score = ($b['score'] <=> $a['score']);

            if ($score !== 0) {
                return $score;
            }

            return strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
        });

        $candidateCount = count($items);
        $items = array_slice($items, 0, $limit);
        $items = $this->annotateWithEvidenceRefs($items);
        $version = $this->searchVersion($validated['project_id'], $binding->id, $query, $domain, $filters, $items);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'version' => $version,
            'etag' => $version,
            'query' => $query,
            'domain' => $domain,
            'filters' => $filters,
            'limit' => $limit,
            'include_raw_chunks' => $includeRawChunks,
            'count' => count($items),
            'candidate_count' => $candidateCount,
            'truncated' => $candidateCount > count($items),
            'raw_chunks_omitted' => $rawChunksOmitted,
            'freshness' => $this->awareness->freshnessForBinding($binding),
            'items' => array_values($items),
            'server_time' => now()->toISOString(),
        ]);
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $bindingId)
            ->where('project_id', $projectId)
            ->where('hades_agent_id', $agent->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        if ($binding->status !== 'linked') {
            return $this->error('workspace_binding_unlinked', 'Workspace binding is not linked.', Response::HTTP_CONFLICT);
        }

        return $binding;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function memoryResults(string $projectId, string $query, string $domain, array $filters, string $workspaceBindingId, int $limit, bool $includeRawChunks, string $workspaceHeadCommit = ''): array
    {
        $tokens = $this->tokens($query);
        $indexedDomains = match ($domain) {
            'all', 'project_memory' => ['project_memory', 'logbook', 'agent_notes', 'source_chunks'],
            'logbook' => ['logbook'],
            'agent_notes' => ['agent_notes'],
            'source_chunks' => ['source_chunks'],
            default => ['project_memory', 'logbook', 'agent_notes', 'source_chunks'],
        };
        $indexedMemoryScores = $this->searchIndexer->matchingSourceScores($projectId, $workspaceBindingId, $indexedDomains, $query, $filters, $limit);
        $indexedMemoryIds = array_keys($indexedMemoryScores);

        $rows = DB::table('project_memory_entries')
            ->when($indexedMemoryIds !== [], function ($builder) use ($indexedMemoryIds): void {
                $builder->whereIn('id', $indexedMemoryIds);
            })
            ->where('project_id', $projectId)
            ->when(($filters['kind'] ?? '') !== '', function ($builder) use ($filters): void {
                $builder->where('kind', $filters['kind']);
            })
            ->when($this->hasCoarsePayloadFilters($filters), function ($builder) use ($filters): void {
                $builder->where(function ($nested) use ($filters): void {
                    foreach (['schema', 'source', 'symbol', 'path'] as $key) {
                        $value = $filters[$key] ?? '';
                        if ($value === '') {
                            continue;
                        }
                        $like = '%'.$value.'%';
                        if ($key === 'source') {
                            $nested->orWhere('source', 'like', $like);
                        }
                        $nested->orWhere('payload', 'like', $like);
                    }
                });
            })
            ->when($query !== '' && $indexedMemoryIds === [], function ($builder) use ($query, $tokens): void {
                $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                $builder->where(function ($nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $like = '%'.$pattern.'%';
                        $nested
                            ->orWhere('summary', 'like', $like)
                            ->orWhere('payload', 'like', $like)
                            ->orWhere('kind', 'like', $like)
                            ->orWhere('source', 'like', $like)
                            ->orWhere('agent_key', 'like', $like);
                    }
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(max($filters === [] ? 100 : 250, $limit * ($filters === [] ? 12 : 20)))
            ->get();

        $items = [];
        $rawChunksOmitted = 0;

        foreach ($rows as $entry) {
            $payload = $this->decodePayload($entry->payload);
            $rawChunk = $this->isRawChunk($entry, $payload);
            $entryDomain = $rawChunk ? 'source_chunks' : $this->memoryDomain($entry);

            if (! $this->domainMatches($entryDomain, $domain)) {
                continue;
            }

            if ($rawChunk && ! $includeRawChunks) {
                $rawChunksOmitted++;

                continue;
            }

            $schema = $this->payloadString($payload, ['schema', 'content_schema', 'artifact_schema']);
            $source = $this->payloadString($payload, ['path', 'source_path', 'file', 'uri', 'route', 'symbol']);
            $filterFields = $this->memoryFilterFields($entry, $payload, $schema, $source);

            if (! $this->matchesFilters($filterFields, $filters)) {
                continue;
            }

            $matchFields = $this->matchFields($filterFields, $filters);
            $validity = $this->resolvedBugValidity($entry, $payload, $workspaceHeadCommit);
            $documentScore = $indexedMemoryScores[(string) $entry->id] ?? 0;
            $score = max($documentScore, $this->score($query, $tokens, [
                (string) $entry->summary,
                (string) $entry->payload,
                (string) $entry->kind,
                (string) $entry->source,
                (string) $entry->agent_key,
            ]));
            $score += count($matchFields) * 15;
            if ((string) $entry->kind === 'resolved_bug') {
                $score += 12;
            }

            $items[] = [
                'id' => (string) $entry->id,
                'domain' => $entryDomain,
                'source' => $source !== '' ? $source : (string) $entry->source,
                'kind' => (string) $entry->kind,
                'schema' => $schema,
                'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
                'summary' => $this->compact((string) $entry->summary, $rawChunk ? 1200 : 800),
                'payload_excerpt' => $this->excerpt(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'score' => $score,
                'match_fields' => $matchFields,
                'raw_chunk' => $rawChunk,
                'stale' => $validity['stale'],
                'stale_reason' => $validity['stale_reason'],
                'occurred_at' => $this->toIsoString($entry->occurred_at),
                'updated_at' => $this->toIsoString($entry->updated_at),
                'version' => 'mem_'.hash('sha256', $entry->id.'|'.$entry->updated_at),
            ];
        }

        return [$items, $rawChunksOmitted];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wikiResults(string $projectId, string $query, array $filters, int $limit): array
    {
        $tokens = $this->tokens($query);
        $indexedRevisionScores = $this->searchIndexer->matchingSourceScores($projectId, null, ['wiki'], $query, $filters, $limit);
        $indexedRevisionIds = array_keys($indexedRevisionScores);

        return DB::table('wiki_pages')
            ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->where('wiki_pages.project_id', $projectId)
            ->when($indexedRevisionIds !== [], function ($builder) use ($indexedRevisionIds): void {
                $builder->whereIn('wiki_revisions.id', $indexedRevisionIds);
            })
            ->when($query !== '' && $indexedRevisionIds === [], function ($builder) use ($query, $tokens): void {
                $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                $builder->where(function ($nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $like = '%'.$pattern.'%';
                        $nested
                            ->orWhere('wiki_pages.title', 'like', $like)
                            ->orWhere('wiki_pages.slug', 'like', $like)
                            ->orWhere('wiki_pages.page_type', 'like', $like)
                            ->orWhere('wiki_revisions.content_markdown', 'like', $like)
                            ->orWhere('wiki_revisions.evidence_refs', 'like', $like);
                    }
                });
            })
            ->select([
                'wiki_pages.id as page_id',
                'wiki_pages.slug',
                'wiki_pages.title',
                'wiki_pages.page_type',
                'wiki_pages.source_status as page_source_status',
                'wiki_pages.updated_at',
                'wiki_revisions.id as revision_id',
                'wiki_revisions.source_type',
                'wiki_revisions.source_status as revision_source_status',
                'wiki_revisions.content_markdown',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at as revision_created_at',
            ])
            ->orderByDesc('wiki_pages.updated_at')
            ->orderByDesc('wiki_revisions.created_at')
            ->limit(max($filters === [] ? 50 : 150, $limit * ($filters === [] ? 8 : 15)))
            ->get()
            ->map(function (object $row) use ($filters, $indexedRevisionScores, $query, $tokens): ?array {
                $filterFields = [
                    'kind' => ['wiki'],
                    'schema' => ['devboard.wiki_revision.v1'],
                    'source' => ['wiki_revision', (string) $row->source_type],
                    'path' => [(string) $row->slug, (string) $row->evidence_refs],
                    'symbol' => [(string) $row->title, (string) $row->content_markdown, (string) $row->evidence_refs],
                ];

                if (! $this->matchesFilters($filterFields, $filters)) {
                    return null;
                }

                $matchFields = $this->matchFields($filterFields, $filters);

                return [
                    'id' => (string) $row->revision_id,
                    'domain' => 'wiki',
                    'source' => 'wiki_revision',
                    'kind' => 'wiki',
                    'schema' => 'devboard.wiki_revision.v1',
                    'summary' => (string) $row->title,
                    'page_id' => (string) $row->page_id,
                    'page_slug' => (string) $row->slug,
                    'page_type' => (string) $row->page_type,
                    'source_type' => (string) $row->source_type,
                    'source_status' => (string) $row->revision_source_status,
                    'payload_excerpt' => $this->excerpt((string) $row->content_markdown),
                    'evidence_count' => count($this->decodeList($row->evidence_refs)),
                    'score' => max($indexedRevisionScores[(string) $row->revision_id] ?? 0, $this->score($query, $tokens, [
                        (string) $row->title,
                        (string) $row->slug,
                        (string) $row->page_type,
                        (string) $row->content_markdown,
                        (string) $row->evidence_refs,
                    ])) + count($matchFields) * 15,
                    'match_fields' => $matchFields,
                    'raw_chunk' => false,
                    'occurred_at' => $this->toIsoString($row->revision_created_at),
                    'updated_at' => $this->toIsoString($row->updated_at),
                    'version' => 'wiki_'.hash('sha256', $row->revision_id.'|'.$row->updated_at),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artifactResults(string $projectId, string $bindingId, string $query, array $filters, int $limit): array
    {
        $tokens = $this->tokens($query);
        $indexedArtifactScores = $this->searchIndexer->matchingSourceScores($projectId, $bindingId, ['artifacts'], $query, $filters, $limit, false);
        $indexedArtifactIds = array_keys($indexedArtifactScores);

        return DB::table('hades_agent_artifacts')
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $bindingId)
            ->when($indexedArtifactIds !== [], function ($builder) use ($indexedArtifactIds): void {
                $builder->whereIn('id', $indexedArtifactIds);
            })
            ->when(($filters['schema'] ?? '') !== '', function ($builder) use ($filters): void {
                $builder->where('schema', 'like', '%'.$filters['schema'].'%');
            })
            ->when($query !== '' && $indexedArtifactIds === [], function ($builder) use ($query, $tokens): void {
                $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                $builder->where(function ($nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $like = '%'.$pattern.'%';
                        $nested
                            ->orWhere('schema', 'like', $like)
                            ->orWhere('artifact', 'like', $like);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max($filters === [] ? 50 : 150, $limit * ($filters === [] ? 8 : 15)))
            ->get()
            ->map(function (object $row) use ($filters, $indexedArtifactScores, $query, $tokens): ?array {
                $artifact = $this->decodePayload($row->artifact);
                $summary = $this->artifactSummary((string) $row->schema, $artifact);
                $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
                $filterFields = $this->artifactFilterFields((string) $row->schema, $artifact, $summary, $encoded);

                if (! $this->matchesFilters($filterFields, $filters)) {
                    return null;
                }

                $matchFields = $this->matchFields($filterFields, $filters);

                return [
                    'id' => (string) $row->id,
                    'domain' => 'artifacts',
                    'source' => (string) $row->schema,
                    'kind' => 'artifact',
                    'schema' => (string) $row->schema,
                    'summary' => $summary,
                    'payload_excerpt' => $this->excerpt($encoded),
                    'score' => max($indexedArtifactScores[(string) $row->id] ?? 0, $this->score($query, $tokens, [
                        $summary,
                        (string) $row->schema,
                        $encoded,
                    ])) + count($matchFields) * 15,
                    'match_fields' => $matchFields,
                    'raw_chunk' => false,
                    'occurred_at' => $this->toIsoString($row->created_at),
                    'updated_at' => $this->toIsoString($row->updated_at),
                    'version' => 'artifact_'.hash('sha256', $row->id.'|'.$row->updated_at.'|'.$row->sha256),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function indexedArtifactIds(string $projectId, string $bindingId, string $query, array $filters, int $limit): array
    {
        if ($query === '' && ($filters['schema'] ?? '') === '') {
            return [];
        }

        $tokens = $this->tokens($query);
        $driver = DB::connection()->getDriverName();
        $useFullText = $query !== '' && in_array($driver, ['mysql', 'pgsql'], true);

        $rows = DB::table('hades_search_documents')
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $bindingId)
            ->where('domain', 'artifacts')
            ->when(($filters['schema'] ?? '') !== '', function ($builder) use ($filters): void {
                $builder->where('source_schema', 'like', '%'.$filters['schema'].'%');
            })
            ->when($useFullText && $driver === 'pgsql', function ($builder) use ($query): void {
                $builder->whereRaw("to_tsvector('english', coalesce(title, '') || ' ' || coalesce(body, '') || ' ' || coalesce(source_schema, '')) @@ plainto_tsquery('english', ?)", [$query]);
            })
            ->when($useFullText && $driver === 'mysql', function ($builder) use ($query, $tokens): void {
                $fullTextQuery = implode(' ', array_map(fn (string $term): string => '+'.$term.'*', array_values(array_unique(array_map('strtolower', array_filter(preg_match_all('/[A-Za-z0-9]{2,}/', $query, $m) ? $m[0] : []))))));
                $builder->whereRaw('MATCH(title, body, source_schema) AGAINST (? IN BOOLEAN MODE)', [$fullTextQuery]);
            })
            ->when($query !== '' && ! $useFullText, function ($builder) use ($query, $tokens): void {
                $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                $builder->where(function ($nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $like = '%'.$pattern.'%';
                        $nested
                            ->orWhere('title', 'like', $like)
                            ->orWhere('body', 'like', $like)
                            ->orWhere('source_schema', 'like', $like);
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->limit(max(50, $limit * 15))
            ->pluck('source_id')
            ->all();

        return array_values(array_unique(array_map('strval', $rows)));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function filters(array $validated): array
    {
        $filters = [];

        foreach (['kind', 'schema', 'source', 'symbol', 'path'] as $key) {
            $value = trim((string) ($validated[$key] ?? ''));
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function hasCoarsePayloadFilters(array $filters): bool
    {
        foreach (['schema', 'source', 'symbol', 'path'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function payloadValues(array $payload, array $keys): array
    {
        $containers = [$payload];
        foreach (['metadata', 'provenance', 'payload'] as $nestedKey) {
            if (isset($payload[$nestedKey]) && is_array($payload[$nestedKey])) {
                $containers[] = $payload[$nestedKey];
            }
        }

        $values = [];
        foreach ($containers as $container) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $container)) {
                    $rendered = $this->filterValue($container[$key]);
                    if ($rendered !== '') {
                        $values[] = $rendered;
                    }
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private function memoryFilterFields(object $entry, array $payload, string $schema, string $source): array
    {
        return [
            'kind' => [(string) $entry->kind],
            'schema' => [$schema],
            'source' => [(string) $entry->source, $source],
            'path' => $this->payloadValues($payload, ['path', 'source_path', 'file', 'uri']),
            'symbol' => $this->payloadValues($payload, ['symbol', 'symbols', 'affected_symbols', 'name', 'class', 'handler']),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, list<string>>
     */
    private function artifactFilterFields(string $schema, array $artifact, string $summary, string $encoded): array
    {
        return [
            'kind' => ['artifact'],
            'schema' => [$schema, $this->payloadString($artifact, ['schema', 'artifact_schema'])],
            'source' => [$schema],
            'path' => array_merge(
                $this->payloadValues($artifact, ['path', 'source_path', 'file', 'uri']),
                [$summary, $encoded],
            ),
            'symbol' => array_merge(
                $this->payloadValues($artifact, ['symbol', 'symbols', 'affected_symbols', 'name', 'class', 'handler']),
                [$summary, $encoded],
            ),
        ];
    }

    private function filterValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, string>  $filters
     */
    private function matchesFilters(array $fields, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            $expected = Str::lower(trim($expected));
            if ($expected === '') {
                continue;
            }

            $actual = Str::lower(implode(PHP_EOL, array_map(fn (mixed $value): string => $this->filterValue($value), $fields[$key] ?? [])));
            if ($key === 'kind') {
                if ($actual !== $expected) {
                    return false;
                }

                continue;
            }

            if (! str_contains($actual, $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function matchFields(array $fields, array $filters): array
    {
        $matched = [];

        foreach ($filters as $key => $expected) {
            $expected = Str::lower(trim($expected));
            if ($expected === '') {
                continue;
            }

            $actual = Str::lower(implode(PHP_EOL, array_map(fn (mixed $value): string => $this->filterValue($value), $fields[$key] ?? [])));
            if (($key === 'kind' && $actual === $expected) || ($key !== 'kind' && str_contains($actual, $expected))) {
                $matched[] = $key;
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function artifactSummary(string $schema, array $artifact): string
    {
        if (in_array($schema, ['hades.php_graph.v1', 'hades.code_graph.v1'], true) || in_array($artifact['schema'] ?? null, ['hades.php_graph.v1', 'hades.code_graph.v1'], true)) {
            return $this->codeGraphSummary($schema, $artifact);
        }

        $index = isset($artifact['project_index']) && is_array($artifact['project_index'])
            ? $artifact['project_index']
            : [];
        $parts = [];

        $routes = isset($index['routes']) && is_array($index['routes']) ? array_slice($index['routes'], 0, 8) : [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $method = trim((string) ($route['method'] ?? ''));
            $uri = trim((string) ($route['uri'] ?? $route['path'] ?? ''));
            $handler = trim((string) ($route['handler'] ?? ''));
            $name = trim((string) ($route['name'] ?? ''));
            $routeText = trim($method.' '.$uri.($handler !== '' ? ' -> '.$handler : '').($name !== '' ? ' ['.$name.']' : ''));
            if ($routeText !== '') {
                $parts[] = $routeText;
            }
        }

        $manifests = isset($index['dependency_manifests']) && is_array($index['dependency_manifests'])
            ? array_slice($index['dependency_manifests'], 0, 6)
            : [];
        foreach ($manifests as $manifest) {
            if (! is_array($manifest)) {
                continue;
            }
            $manager = trim((string) ($manifest['manager'] ?? 'deps'));
            $packages = isset($manifest['packages']) && is_array($manifest['packages'])
                ? array_slice(array_map('strval', $manifest['packages']), 0, 8)
                : [];
            if ($packages !== []) {
                $parts[] = $manager.': '.implode(', ', $packages);
            }
        }

        $database = isset($index['database']) && is_array($index['database']) ? $index['database'] : [];
        $migrationCount = isset($database['migration_count'])
            ? (int) $database['migration_count']
            : count(is_array($database['migrations'] ?? null) ? $database['migrations'] : []);
        if ($migrationCount > 0) {
            $parts[] = $migrationCount.' database migration(s)';
        }

        $fallback = $this->payloadString($artifact, ['summary']);
        $body = $parts !== [] ? implode('; ', $parts) : ($fallback !== '' ? $fallback : 'indexed project artifact');

        return $this->compact($schema.' project index: '.$body, 800);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function codeGraphSummary(string $schema, array $artifact): string
    {
        $parts = [];

        $routes = isset($artifact['routes']) && is_array($artifact['routes']) ? array_slice($artifact['routes'], 0, 8) : [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $method = trim((string) ($route['method'] ?? ''));
            $uri = trim((string) ($route['uri'] ?? $route['path'] ?? ''));
            $handler = trim((string) ($route['handler'] ?? ''));
            $name = trim((string) ($route['name'] ?? ''));
            $routeText = trim($method.' '.$uri.($handler !== '' ? ' -> '.$handler : '').($name !== '' ? ' ['.$name.']' : ''));
            if ($routeText !== '') {
                $parts[] = $routeText;
            }
        }

        $symbols = isset($artifact['symbols']) && is_array($artifact['symbols']) ? array_slice($artifact['symbols'], 0, 10) : [];
        foreach ($symbols as $symbol) {
            if (! is_array($symbol)) {
                continue;
            }
            $name = trim((string) ($symbol['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $kind = trim((string) ($symbol['kind'] ?? 'symbol'));
            $role = trim((string) ($symbol['role'] ?? ''));
            $parts[] = trim($kind.' '.$name.($role !== '' ? ' ['.$role.']' : ''));
        }

        $framework = trim((string) ($artifact['framework'] ?? ''));
        if ($framework !== '') {
            $parts[] = 'framework: '.$framework;
        }

        $edges = isset($artifact['edges']) && is_array($artifact['edges']) ? $artifact['edges'] : [];
        if ($edges !== []) {
            $edgeKinds = [];
            foreach ($edges as $edge) {
                if (is_array($edge)) {
                    $kind = trim((string) ($edge['kind'] ?? 'edge'));
                    $edgeKinds[$kind] = ($edgeKinds[$kind] ?? 0) + 1;
                }
            }
            ksort($edgeKinds);
            $parts[] = count($edges).' graph edge(s): '.implode(', ', array_map(
                fn (string $kind, int $count): string => $kind.':'.$count,
                array_keys($edgeKinds),
                array_values($edgeKinds),
            ));
        }

        $fallback = $this->payloadString($artifact, ['summary']);
        $body = $parts !== [] ? implode('; ', $parts) : ($fallback !== '' ? $fallback : 'indexed code graph artifact');

        return $this->compact($schema.' code graph: '.$body, 800);
    }

    private function domainMatches(string $entryDomain, string $requestedDomain): bool
    {
        if ($requestedDomain === 'all') {
            return true;
        }

        if ($requestedDomain === 'project_memory') {
            return $entryDomain !== 'wiki';
        }

        return $entryDomain === $requestedDomain;
    }

    private function memoryDomain(object $entry): string
    {
        if ((string) $entry->kind === 'resolved_bug') {
            return 'project_memory';
        }

        if ((string) $entry->kind === 'agent_note'
            || in_array((string) $entry->source, ['server_agent', 'hades_agent'], true)
            || $entry->agent_key !== null) {
            return 'agent_notes';
        }

        return 'logbook';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{stale: bool, stale_reason: string|null}
     */
    private function resolvedBugValidity(object $entry, array $payload, string $workspaceHeadCommit): array
    {
        if ((string) $entry->kind !== 'resolved_bug') {
            return ['stale' => false, 'stale_reason' => null];
        }

        $validity = isset($payload['validity']) && is_array($payload['validity']) ? $payload['validity'] : [];
        $freshness = isset($payload['freshness']) && is_array($payload['freshness']) ? $payload['freshness'] : [];
        $validFromCommit = trim((string) ($validity['valid_from_commit'] ?? $freshness['workspace_head_commit'] ?? ''));
        $currentHead = trim($workspaceHeadCommit);

        if ($validFromCommit !== '' && $currentHead !== '' && ! hash_equals($validFromCommit, $currentHead)) {
            return ['stale' => true, 'stale_reason' => 'workspace_head_changed'];
        }

        return ['stale' => false, 'stale_reason' => null];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isRawChunk(object $entry, array $payload): bool
    {
        $schema = Str::lower($this->payloadString($payload, ['schema', 'content_schema', 'artifact_schema']));
        $kind = Str::lower((string) $entry->kind);
        $source = Str::lower((string) $entry->source);
        $summary = Str::lower((string) $entry->summary);

        if (isset($payload['chunk_index']) || isset($payload['chunk_count'])) {
            return true;
        }

        foreach (self::RAW_CHUNK_MARKERS as $marker) {
            if (str_contains($schema, $marker)
                || str_contains($kind, $marker)
                || str_contains($source, $marker)
                || str_contains($summary, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        preg_match_all('/[A-Za-z0-9_.:\/-]{2,}/', $query, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $haystacks
     */
    private function score(string $query, array $tokens, array $haystacks): int
    {
        $query = Str::lower(trim($query));
        $fields = [
            ['text' => Str::lower($haystacks[0] ?? ''), 'weight' => 12],
            ['text' => Str::lower($haystacks[1] ?? ''), 'weight' => 7],
            ['text' => Str::lower($haystacks[2] ?? ''), 'weight' => 5],
            ['text' => Str::lower($haystacks[3] ?? ''), 'weight' => 4],
            ['text' => Str::lower($haystacks[4] ?? ''), 'weight' => 3],
        ];
        $summary = $fields[0]['text'];
        $joined = Str::lower(implode(PHP_EOL, $haystacks));
        $score = 0;
        $matchedTokens = [];

        if ($query !== '' && str_contains($summary, $query)) {
            $score += 80;
        } elseif ($query !== '' && str_contains($joined, $query)) {
            $score += 35;
        }

        foreach ($fields as $field) {
            $text = trim((string) $field['text']);
            if ($text === '') {
                continue;
            }

            $fieldMatches = 0;
            foreach ($tokens as $token) {
                if (! str_contains($text, $token)) {
                    continue;
                }

                $matchedTokens[$token] = true;
                $fieldMatches++;
                $occurrences = substr_count($text, $token);
                $score += (int) $field['weight'] + min(4, $occurrences) * 2 + min(12, strlen($token));
            }

            if ($fieldMatches > 1) {
                $score += $this->fieldDensityBonus($text, $fieldMatches, (int) $field['weight']);
            }
        }

        $matchedCount = count($matchedTokens);
        if ($matchedCount > 1) {
            $score += $matchedCount * $matchedCount * 3;
        }

        if ($tokens !== [] && $matchedCount === count($tokens)) {
            $score += 20 + (count($tokens) * 5);
        }

        return $score;
    }

    private function fieldDensityBonus(string $text, int $matches, int $weight): int
    {
        preg_match_all('/[a-z0-9_.:\/-]{2,}/', $text, $words);
        $wordCount = max(1, count($words[0] ?? []));

        return intdiv($matches * $matches * $weight * 20, min(200, $wordCount + 10));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<mixed>
     */
    private function decodeList(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function payloadString(array $payload, array $keys): string
    {
        $containers = [$payload];
        foreach (['metadata', 'provenance', 'payload'] as $nestedKey) {
            if (isset($payload[$nestedKey]) && is_array($payload[$nestedKey])) {
                $containers[] = $payload[$nestedKey];
            }
        }

        foreach ($containers as $container) {
            foreach ($keys as $key) {
                if (isset($container[$key]) && trim((string) $container[$key]) !== '') {
                    return trim((string) $container[$key]);
                }
            }
        }

        return '';
    }

    private function excerpt(string $content): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', strip_tags($content)));

        return $this->compact($normalized, 500);
    }

    private function compact(string $content, int $limit): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $content));

        if (Str::length($normalized) <= $limit) {
            return $normalized;
        }

        return rtrim(Str::substr($normalized, 0, max(0, $limit - 3))).'...';
    }

    private function toIsoString(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function searchVersion(string $projectId, string $bindingId, string $query, string $domain, array $filters, array $items): string
    {
        ksort($filters);
        $material = [$projectId, $bindingId, $query, $domain, json_encode($filters), (string) count($items)];
        foreach ($items as $item) {
            $material[] = ($item['id'] ?? '').':'.($item['version'] ?? '').':'.($item['score'] ?? '');
        }

        return 'search_'.hash('sha256', implode('|', $material));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function annotateWithEvidenceRefs(array $items): array
    {
        if (! $this->embeddingIndex->supportsEmbeddings()) {
            return $items;
        }

        $annotated = [];

        foreach ($items as $item) {
            $sourceTable = $this->sourceTableForDomain($item['domain'] ?? '', $item['source'] ?? '', $item['kind'] ?? '');
            $evidenceRefs = [];
            $needsVerification = false;

            if ($sourceTable !== '' && isset($item['id'])) {
                $refs = $this->embeddingIndex->extractEvidenceRefsForSource($sourceTable, (string) $item['id']);

                if ($refs !== []) {
                    $evidenceRefs = $refs;
                } else {
                    $needsVerification = true;
                }
            } else {
                $needsVerification = true;
            }

            $item['evidence_refs'] = $evidenceRefs;
            $item['needs_verification'] = $needsVerification;
            $annotated[] = $item;
        }

        return $annotated;
    }

    private function sourceTableForDomain(string $domain, string $source, string $kind): string
    {
        return match ($domain) {
            'wiki' => 'wiki_revisions',
            'artifacts' => 'hades_agent_artifacts',
            'evidence_packs' => 'hades_evidence_packs',
            'causal_packs' => 'hades_causal_packs',
            'source_slices' => 'hades_source_slices',
            'bug_evidence' => 'hades_bug_evidence_items',
            'project_memory', 'logbook', 'agent_notes', 'source_chunks' => 'project_memory_entries',
            default => '',
        };
    }
}
