<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
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

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'query' => ['nullable', 'string', 'max:1000'],
            'domain' => ['nullable', 'string', 'in:all,project_memory,logbook,wiki,agent_notes,source_chunks,artifacts'],
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
        $rawChunksOmitted = 0;
        $items = [];

        if (! in_array($domain, ['wiki', 'artifacts'], true)) {
            [$memoryItems, $omitted] = $this->memoryResults(
                projectId: $validated['project_id'],
                query: $query,
                domain: $domain,
                limit: $limit,
                includeRawChunks: $includeRawChunks,
            );
            $items = array_merge($items, $memoryItems);
            $rawChunksOmitted += $omitted;
        }

        if (in_array($domain, ['all', 'wiki'], true)) {
            $items = array_merge($items, $this->wikiResults($validated['project_id'], $query, $limit));
        }

        if (in_array($domain, ['all', 'artifacts'], true)) {
            $items = array_merge($items, $this->artifactResults($validated['project_id'], $binding->id, $query, $limit));
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
        $version = $this->searchVersion($validated['project_id'], $binding->id, $query, $domain, $items);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'version' => $version,
            'etag' => $version,
            'query' => $query,
            'domain' => $domain,
            'limit' => $limit,
            'include_raw_chunks' => $includeRawChunks,
            'count' => count($items),
            'candidate_count' => $candidateCount,
            'truncated' => $candidateCount > count($items),
            'raw_chunks_omitted' => $rawChunksOmitted,
            'freshness' => [
                'workspace_head_commit' => $binding->head_commit,
                'index_status' => 'live_query',
                'stale_reason' => null,
            ],
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
    private function memoryResults(string $projectId, string $query, string $domain, int $limit, bool $includeRawChunks): array
    {
        $tokens = $this->tokens($query);
        $rows = DB::table('project_memory_entries')
            ->where('project_id', $projectId)
            ->when($query !== '', function ($builder) use ($query, $tokens): void {
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
            ->limit(max(100, $limit * 12))
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

            $items[] = [
                'id' => (string) $entry->id,
                'domain' => $entryDomain,
                'source' => $source !== '' ? $source : (string) $entry->source,
                'kind' => (string) $entry->kind,
                'schema' => $schema,
                'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
                'summary' => $this->compact((string) $entry->summary, $rawChunk ? 1200 : 800),
                'payload_excerpt' => $this->excerpt(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'score' => $this->score($query, $tokens, [
                    (string) $entry->summary,
                    (string) $entry->payload,
                    (string) $entry->kind,
                    (string) $entry->source,
                    (string) $entry->agent_key,
                ]),
                'raw_chunk' => $rawChunk,
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
    private function wikiResults(string $projectId, string $query, int $limit): array
    {
        $tokens = $this->tokens($query);

        return DB::table('wiki_pages')
            ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->where('wiki_pages.project_id', $projectId)
            ->when($query !== '', function ($builder) use ($query, $tokens): void {
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
            ->limit(max(50, $limit * 8))
            ->get()
            ->map(fn (object $row): array => [
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
                'score' => $this->score($query, $tokens, [
                    (string) $row->title,
                    (string) $row->slug,
                    (string) $row->page_type,
                    (string) $row->content_markdown,
                    (string) $row->evidence_refs,
                ]),
                'raw_chunk' => false,
                'occurred_at' => $this->toIsoString($row->revision_created_at),
                'updated_at' => $this->toIsoString($row->updated_at),
                'version' => 'wiki_'.hash('sha256', $row->revision_id.'|'.$row->updated_at),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artifactResults(string $projectId, string $bindingId, string $query, int $limit): array
    {
        $tokens = $this->tokens($query);

        return DB::table('hades_agent_artifacts')
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $bindingId)
            ->when($query !== '', function ($builder) use ($query, $tokens): void {
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
            ->limit(max(50, $limit * 8))
            ->get()
            ->map(function (object $row) use ($query, $tokens): array {
                $artifact = $this->decodePayload($row->artifact);
                $summary = $this->artifactSummary((string) $row->schema, $artifact);
                $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

                return [
                    'id' => (string) $row->id,
                    'domain' => 'artifacts',
                    'source' => (string) $row->schema,
                    'kind' => 'artifact',
                    'schema' => (string) $row->schema,
                    'summary' => $summary,
                    'payload_excerpt' => $this->excerpt($encoded),
                    'score' => $this->score($query, $tokens, [
                        $summary,
                        (string) $row->schema,
                        $encoded,
                    ]),
                    'raw_chunk' => false,
                    'occurred_at' => $this->toIsoString($row->created_at),
                    'updated_at' => $this->toIsoString($row->updated_at),
                    'version' => 'artifact_'.hash('sha256', $row->id.'|'.$row->updated_at.'|'.$row->sha256),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function artifactSummary(string $schema, array $artifact): string
    {
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
            $uri = trim((string) ($route['uri'] ?? ''));
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
        if ((string) $entry->kind === 'agent_note'
            || in_array((string) $entry->source, ['server_agent', 'hades_agent'], true)
            || $entry->agent_key !== null) {
            return 'agent_notes';
        }

        return 'logbook';
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
        $summary = Str::lower($haystacks[0] ?? '');
        $joined = Str::lower(implode(PHP_EOL, $haystacks));
        $score = 0;

        if ($query !== '' && str_contains($summary, $query)) {
            $score += 40;
        } elseif ($query !== '' && str_contains($joined, $query)) {
            $score += 20;
        }

        foreach ($tokens as $token) {
            if (str_contains($summary, $token)) {
                $score += 4;
            } elseif (str_contains($joined, $token)) {
                $score += 2;
            }
        }

        return $score;
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
    private function searchVersion(string $projectId, string $bindingId, string $query, string $domain, array $items): string
    {
        $material = [$projectId, $bindingId, $query, $domain, (string) count($items)];
        foreach ($items as $item) {
            $material[] = ($item['id'] ?? '').':'.($item['version'] ?? '').':'.($item['score'] ?? '');
        }

        return 'search_'.hash('sha256', implode('|', $material));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
