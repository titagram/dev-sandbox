<?php

namespace App\Services\Hades;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HadesSearchDocumentIndexer
{
    private const RAW_CHUNK_MARKERS = [
        'file_chunk',
        'source_chunk',
        'backend_wiki.file_chunk',
        '---begin_content---',
    ];

    /**
     * @param  array<string, mixed>  $artifactPayload
     */
    public function indexArtifact(object $artifact, array $artifactPayload, string $artifactJson): void
    {
        DB::table('hades_search_documents')->updateOrInsert(
            [
                'source_table' => 'hades_agent_artifacts',
                'source_id' => $artifact->id,
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $artifact->project_id,
                'workspace_binding_id' => $artifact->workspace_binding_id,
                'domain' => 'artifacts',
                'kind' => 'artifact',
                'source_schema' => $artifact->schema,
                'title' => $this->artifactTitle((string) $artifact->schema, $artifactPayload),
                'body' => $this->documentBody($artifactPayload, $artifactJson),
                'metadata' => json_encode([
                    'schema' => $artifact->schema,
                    'sha256' => $artifact->sha256,
                    'truncated' => (bool) $artifact->truncated,
                    'redactions' => (int) $artifact->redactions,
                ], JSON_THROW_ON_ERROR),
                'checksum' => hash('sha256', $artifact->schema.'|'.$artifact->sha256.'|'.$artifactJson),
                'created_at' => $artifact->created_at,
                'updated_at' => now(),
            ],
        );
    }

    public function indexMemoryEntry(object $entry): void
    {
        $payload = $this->decode($entry->payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $schema = $this->payloadString($payload, ['schema', 'content_schema', 'artifact_schema']);

        DB::table('hades_search_documents')->updateOrInsert(
            [
                'source_table' => 'project_memory_entries',
                'source_id' => $entry->id,
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $entry->project_id,
                'workspace_binding_id' => null,
                'domain' => $this->memoryDomain($entry, $payload),
                'kind' => (string) $entry->kind,
                'source_schema' => $schema !== '' ? $schema : null,
                'title' => $this->compact((string) $entry->summary, 255),
                'body' => $this->documentBody($payload, trim((string) $entry->summary."\n".$payloadJson."\n".(string) $entry->source."\n".(string) $entry->agent_key)),
                'metadata' => json_encode([
                    'source' => $entry->source,
                    'agent_key' => $entry->agent_key,
                    'repository_id' => $entry->repository_id,
                    'task_id' => $entry->task_id,
                    'run_id' => $entry->run_id,
                    'completeness' => $entry->completeness,
                    'occurred_at' => $entry->occurred_at,
                ], JSON_THROW_ON_ERROR),
                'checksum' => hash('sha256', (string) $entry->kind.'|'.(string) $entry->summary.'|'.$payloadJson),
                'created_at' => $entry->created_at,
                'updated_at' => now(),
            ],
        );
    }

    public function indexWikiRevision(object $page, object $revision): void
    {
        $body = trim(implode("\n", array_filter([
            (string) $page->title,
            (string) $page->slug,
            (string) $page->page_type,
            (string) $revision->source_type,
            (string) $revision->source_status,
            (string) $revision->content_markdown,
            (string) $revision->evidence_refs,
        ])));

        DB::table('hades_search_documents')->updateOrInsert(
            [
                'source_table' => 'wiki_revisions',
                'source_id' => $revision->id,
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $page->project_id,
                'workspace_binding_id' => null,
                'domain' => 'wiki',
                'kind' => 'wiki',
                'source_schema' => 'devboard.wiki_revision.v1',
                'title' => $this->compact((string) $page->title, 255),
                'body' => $this->compact($body, 200000),
                'metadata' => json_encode([
                    'wiki_page_id' => $page->id,
                    'slug' => $page->slug,
                    'page_type' => $page->page_type,
                    'source_type' => $revision->source_type,
                    'source_status' => $revision->source_status,
                ], JSON_THROW_ON_ERROR),
                'checksum' => hash('sha256', $body),
                'created_at' => $revision->created_at,
                'updated_at' => now(),
            ],
        );
    }

    public function indexBugEvidence(object $item): void
    {
        $payload = $this->decode($item->payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        DB::table('hades_search_documents')->updateOrInsert(
            [
                'source_table' => 'hades_bug_evidence_items',
                'source_id' => $item->id,
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $item->project_id,
                'workspace_binding_id' => $item->workspace_binding_id,
                'domain' => 'bug_evidence',
                'kind' => (string) $item->kind,
                'source_schema' => $this->payloadString($payload, ['schema']) ?: 'hades.bug_evidence_item.v1',
                'title' => $this->compact((string) $item->summary, 255),
                'body' => $this->documentBody($payload, trim((string) $item->summary."\n".$payloadJson."\n".(string) $item->source)),
                'metadata' => json_encode([
                    'bug_report_id' => $item->bug_report_id,
                    'source' => $item->source,
                    'retention_class' => $item->retention_class,
                    'redactions' => (int) $item->redactions,
                    'occurred_at' => $item->occurred_at,
                ], JSON_THROW_ON_ERROR),
                'checksum' => hash('sha256', (string) $item->kind.'|'.$item->sha256.'|'.$payloadJson),
                'created_at' => $item->created_at,
                'updated_at' => now(),
            ],
        );
    }

    public function indexSourceSlice(object $slice): void
    {
        $title = trim((string) $slice->path.' '.(string) $slice->symbol.' lines '.(string) $slice->start_line.'-'.(string) $slice->end_line);
        $body = trim(implode("\n", array_filter([
            (string) $slice->path,
            (string) $slice->symbol,
            (string) $slice->language,
            (string) $slice->head_commit,
            (string) $slice->content_redacted,
        ])));

        DB::table('hades_search_documents')->updateOrInsert(
            [
                'source_table' => 'hades_source_slices',
                'source_id' => $slice->id,
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $slice->project_id,
                'workspace_binding_id' => $slice->workspace_binding_id,
                'domain' => 'source_slices',
                'kind' => 'source_slice',
                'source_schema' => 'hades.source_slice.v1',
                'title' => $this->compact($title, 255),
                'body' => $this->compact($body, 200000),
                'metadata' => json_encode([
                    'path' => $slice->path,
                    'symbol' => $slice->symbol,
                    'language' => $slice->language,
                    'head_commit' => $slice->head_commit,
                    'retention_class' => $slice->retention_class,
                    'policy' => $slice->policy,
                    'redactions' => (int) $slice->redactions,
                ], JSON_THROW_ON_ERROR),
                'checksum' => hash('sha256', (string) $slice->sha256.'|'.$body),
                'created_at' => $slice->created_at,
                'updated_at' => now(),
            ],
        );
    }

    public function indexEvidencePack(object $pack): void
    {
        $body = trim(implode("\n", array_filter([
            (string) $pack->title,
            (string) $pack->summary,
            (string) $pack->evidence_refs,
            (string) $pack->graph_refs,
            (string) $pack->source_slice_ids,
            (string) $pack->payload,
            (string) $pack->head_commit,
        ])));

        DB::table('hades_search_documents')->updateOrInsert(
            [
                'source_table' => 'hades_evidence_packs',
                'source_id' => $pack->id,
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $pack->project_id,
                'workspace_binding_id' => $pack->workspace_binding_id,
                'domain' => 'evidence_packs',
                'kind' => 'evidence_pack',
                'source_schema' => 'hades.evidence_pack.v1',
                'title' => $this->compact((string) $pack->title, 255),
                'body' => $this->compact($body, 200000),
                'metadata' => json_encode([
                    'bug_report_id' => $pack->bug_report_id,
                    'head_commit' => $pack->head_commit,
                    'retention_class' => $pack->retention_class,
                    'redactions' => (int) $pack->redactions,
                ], JSON_THROW_ON_ERROR),
                'checksum' => hash('sha256', (string) $pack->sha256.'|'.$body),
                'created_at' => $pack->created_at,
                'updated_at' => now(),
            ],
        );
    }

    public function indexCausalPack(object $pack): void
    {
        $body = trim(implode("\n", array_filter([
            (string) $pack->bug_id,
            (string) $pack->root_cause_id,
            (string) $pack->bug_class,
            (string) $pack->failure_classification,
            (string) $pack->affected_refs,
            (string) $pack->freshness,
            (string) $pack->awareness,
            (string) $pack->evidence_refs,
            (string) $pack->graph_refs,
            (string) $pack->source_slice_refs,
            (string) $pack->replay,
            (string) $pack->status,
            (string) $pack->blockers,
        ])));

        DB::table('hades_search_documents')->updateOrInsert(
            [
                'source_table' => 'hades_causal_packs',
                'source_id' => $pack->id,
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $pack->project_id,
                'workspace_binding_id' => $pack->workspace_binding_id,
                'domain' => 'causal_packs',
                'kind' => 'causal_pack',
                'source_schema' => 'hades.causal_pack.v1',
                'title' => $this->compact((string) $pack->root_cause_id, 255),
                'body' => $this->compact($body, 200000),
                'metadata' => json_encode([
                    'bug_report_id' => $pack->bug_report_id,
                    'pack_key' => $pack->pack_key,
                    'bug_class' => $pack->bug_class,
                    'failure_classification' => $pack->failure_classification,
                    'status' => $pack->status,
                ], JSON_THROW_ON_ERROR),
                'checksum' => hash('sha256', (string) $pack->pack_key.'|'.$body),
                'created_at' => $pack->created_at,
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @param  list<string>  $domains
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    public function matchingSourceIds(string $projectId, ?string $workspaceBindingId, array $domains, string $query, array $filters, int $limit, bool $includeProjectLevel = true): array
    {
        return array_keys($this->matchingSourceScores($projectId, $workspaceBindingId, $domains, $query, $filters, $limit, $includeProjectLevel));
    }

    /**
     * @param  list<string>  $domains
     * @param  array<string, string>  $filters
     * @return array<string, int>
     */
    public function matchingSourceScores(string $projectId, ?string $workspaceBindingId, array $domains, string $query, array $filters, int $limit, bool $includeProjectLevel = true): array
    {
        if ($query === '' && ! $this->hasFilters($filters)) {
            return [];
        }

        $tokens = $this->tokens($query);
        $fullTextQuery = $this->fullTextQuery($query);
        $useFullText = $fullTextQuery !== '' && $this->supportsFullTextSearch();
        $selectRows = function (bool $withFullText) use ($domains, $filters, $fullTextQuery, $includeProjectLevel, $limit, $projectId, $query, $tokens, $workspaceBindingId) {
            $fullTextMatch = 'MATCH(title, body, source_schema) AGAINST (? IN BOOLEAN MODE)';

            return DB::table('hades_search_documents')
                ->where('project_id', $projectId)
                ->whereIn('domain', $domains)
                ->when($workspaceBindingId !== null, function ($builder) use ($includeProjectLevel, $workspaceBindingId): void {
                    if ($includeProjectLevel) {
                        $builder->where(function ($nested) use ($workspaceBindingId): void {
                            $nested->whereNull('workspace_binding_id')->orWhere('workspace_binding_id', $workspaceBindingId);
                        });

                        return;
                    }

                    $builder->where('workspace_binding_id', $workspaceBindingId);
                })
                ->when(($filters['kind'] ?? '') !== '', function ($builder) use ($filters): void {
                    $builder->where('kind', $filters['kind']);
                })
                ->when(($filters['schema'] ?? '') !== '', function ($builder) use ($filters): void {
                    $builder->where('source_schema', 'like', '%'.$filters['schema'].'%');
                })
                ->when($this->hasBodyFilters($filters), function ($builder) use ($filters): void {
                    $builder->where(function ($nested) use ($filters): void {
                        foreach (['source', 'symbol', 'path'] as $key) {
                            $value = $filters[$key] ?? '';
                            if ($value === '') {
                                continue;
                            }
                            $like = '%'.$value.'%';
                            $nested
                                ->orWhere('title', 'like', $like)
                                ->orWhere('body', 'like', $like)
                                ->orWhere('metadata', 'like', $like);
                        }
                    });
                })
                ->when($withFullText, function ($builder) use ($fullTextMatch, $fullTextQuery): void {
                    $builder->whereRaw($fullTextMatch, [$fullTextQuery]);
                })
                ->when($query !== '' && ! $withFullText, function ($builder) use ($query, $tokens): void {
                    $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                    $builder->where(function ($nested) use ($patterns): void {
                        foreach ($patterns as $pattern) {
                            $like = '%'.$pattern.'%';
                            $nested
                                ->orWhere('title', 'like', $like)
                                ->orWhere('body', 'like', $like)
                                ->orWhere('source_schema', 'like', $like)
                                ->orWhere('metadata', 'like', $like);
                        }
                    });
                })
                ->when(
                    $withFullText,
                    fn ($builder) => $builder->orderByRaw($fullTextMatch.' DESC', [$fullTextQuery]),
                    fn ($builder) => $builder->orderByDesc('updated_at'),
                )
                ->limit(max(50, $limit * 15))
                ->get(['source_id', 'title', 'body', 'source_schema', 'metadata']);
        };

        try {
            $rows = $selectRows($useFullText);
        } catch (QueryException $exception) {
            if (! $useFullText) {
                throw $exception;
            }
            $rows = $selectRows(false);
        }

        $scores = [];
        foreach ($rows as $row) {
            $sourceId = (string) $row->source_id;
            $score = $this->documentScore($query, $tokens, [
                (string) $row->title,
                (string) $row->body,
                (string) $row->source_schema,
                (string) $row->metadata,
            ]);
            $scores[$sourceId] = max($scores[$sourceId] ?? 0, $score);
        }

        arsort($scores);

        return $scores;
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function artifactTitle(string $schema, array $artifact): string
    {
        $parts = [$schema];
        foreach (['root', 'framework', 'language', 'head_commit'] as $key) {
            $value = trim((string) ($artifact[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $this->compact(implode(' ', array_values(array_unique($parts))), 255);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function documentBody(array $payload, string $fallback): string
    {
        $parts = [];
        $count = 0;
        $this->collectScalars($payload, $parts, $count);
        $bodyParts = $fallback !== '' ? [$fallback, ...$parts] : $parts;
        $body = trim(implode("\n", array_values(array_unique($bodyParts))));

        return $this->compact($body, 200000);
    }

    private function collectScalars(mixed $value, array &$parts, int &$count): void
    {
        if ($count >= 3000) {
            return;
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);
            if ($text !== '') {
                $parts[] = $text;
                $count++;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if ($count >= 3000) {
                return;
            }
            if (is_string($key) && $key !== '') {
                $parts[] = $key;
            }
            $this->collectScalars($item, $parts, $count);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function memoryDomain(object $entry, array $payload): string
    {
        if ($this->isRawChunk($entry, $payload)) {
            return 'source_chunks';
        }

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
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function payloadString(array $payload, array $keys): string
    {
        $containers = [$payload];
        foreach (['metadata', 'provenance', 'payload', 'freshness', 'validity'] as $nestedKey) {
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

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function hasFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function hasBodyFilters(array $filters): bool
    {
        foreach (['source', 'symbol', 'path'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $fields
     */
    private function documentScore(string $query, array $tokens, array $fields): int
    {
        if ($query === '' && $tokens === []) {
            return 1;
        }

        $query = Str::lower(trim($query));
        $weightedFields = [
            ['text' => Str::lower($fields[0] ?? ''), 'weight' => 14],
            ['text' => Str::lower($fields[1] ?? ''), 'weight' => 8],
            ['text' => Str::lower($fields[2] ?? ''), 'weight' => 5],
            ['text' => Str::lower($fields[3] ?? ''), 'weight' => 4],
        ];
        $joined = Str::lower(implode(PHP_EOL, $fields));
        $score = 0;
        $matchedTokens = [];

        if ($query !== '' && str_contains($weightedFields[0]['text'], $query)) {
            $score += 90;
        } elseif ($query !== '' && str_contains($joined, $query)) {
            $score += 40;
        }

        foreach ($weightedFields as $field) {
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
                $score += (int) $field['weight'] + min(4, substr_count($text, $token)) * 2 + min(12, strlen($token));
            }

            if ($fieldMatches > 1) {
                $score += $fieldMatches * (int) $field['weight'];
            }
        }

        if ($tokens !== [] && count($matchedTokens) === count($tokens)) {
            $score += 20 + (count($tokens) * 5);
        }

        return $score;
    }

    private function supportsFullTextSearch(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    private function fullTextQuery(string $query): string
    {
        preg_match_all('/[A-Za-z0-9]{2,}/', $query, $matches);
        $terms = array_slice(array_values(array_unique(array_map('strtolower', $matches[0] ?? []))), 0, 12);

        return implode(' ', array_map(fn (string $term): string => '+'.$term.'*', $terms));
    }

    /**
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        preg_match_all('/[A-Za-z0-9_.:\/-]{2,}/', $query, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
    }

    private function compact(string $content, int $limit): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $content));

        if (Str::length($normalized) <= $limit) {
            return $normalized;
        }

        return rtrim(Str::substr($normalized, 0, max(0, $limit - 3))).'...';
    }
}
