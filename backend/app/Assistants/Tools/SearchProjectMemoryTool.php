<?php

namespace App\Assistants\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class SearchProjectMemoryTool implements Tool
{
    public function name(): string
    {
        return 'search_project_memory';
    }

    public function description(): Stringable|string
    {
        return 'Search bounded DevBoard project memory by domain: logbook, wiki, or agent_notes. Returns excerpts only and does not mutate state.';
    }

    public function handle(Request $request): Stringable|string
    {
        return json_encode($this->payload($request->all()), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(array $arguments): array
    {
        $projectId = (string) ($arguments['project_id'] ?? '');
        $domain = $this->domain((string) ($arguments['domain'] ?? 'all'));
        $query = trim((string) ($arguments['query'] ?? ''));
        $limit = min(20, max(1, (int) ($arguments['limit'] ?? 10)));

        $projectExists = DB::table('projects')
            ->where('id', $projectId)
            ->where('status', '!=', 'deleted')
            ->exists();

        if (! $projectExists) {
            return [
                'tool' => $this->name(),
                'source_status' => 'verified_from_code',
                'found' => false,
                'reason' => 'project_not_found_or_deleted',
                'domain' => $domain,
                'query' => $query,
                'results' => [],
            ];
        }

        $results = $domain === 'wiki'
            ? $this->wikiResults($projectId, $query, $limit)
            : $this->memoryResults($projectId, $domain, $query, $limit);

        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'found' => $results !== [],
            'domain' => $domain,
            'query' => $query,
            'limit' => $limit,
            'domains' => $this->domainCounts($projectId),
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The DevBoard project ULID whose memory should be searched.')
                ->required(),
            'domain' => $schema->string()
                ->description('Memory domain to search.')
                ->enum(['all', 'logbook', 'wiki', 'agent_notes']),
            'query' => $schema->string()
                ->description('Text to search in summaries, wiki content, and bounded payload excerpts.'),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return, capped at 20.')
                ->min(1)
                ->max(20),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memoryResults(string $projectId, string $domain, string $query, int $limit): array
    {
        return DB::table('project_memory_entries')
            ->where('project_id', $projectId)
            ->when($domain === 'logbook', function ($builder): void {
                $builder->where(function ($nested): void {
                    $nested->whereNull('agent_key')->orWhere('agent_key', '');
                })->where('kind', '!=', 'agent_note')
                    ->whereNotIn('source', ['server_agent', 'hades_agent']);
            })
            ->when($domain === 'agent_notes', function ($builder): void {
                $builder->where(function ($nested): void {
                    $nested
                        ->where('kind', 'agent_note')
                        ->orWhereIn('source', ['server_agent', 'hades_agent'])
                        ->orWhereNotNull('agent_key');
                });
            })
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.$query.'%';
                $builder->where(function ($nested) use ($like): void {
                    $nested
                        ->where('summary', 'like', $like)
                        ->orWhere('payload', 'like', $like)
                        ->orWhere('kind', 'like', $like)
                        ->orWhere('source', 'like', $like)
                        ->orWhere('agent_key', 'like', $like);
                });
            })
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $entry): array => [
                'id' => (string) $entry->id,
                'domain' => $this->memoryDomain($entry),
                'source' => (string) $entry->source,
                'kind' => (string) $entry->kind,
                'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
                'summary' => (string) $entry->summary,
                'payload_excerpt' => $this->excerpt((string) $entry->payload),
                'occurred_at' => (string) $entry->occurred_at,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wikiResults(string $projectId, string $query, int $limit): array
    {
        return DB::table('wiki_pages')
            ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->where('wiki_pages.project_id', $projectId)
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.$query.'%';
                $builder->where(function ($nested) use ($like): void {
                    $nested
                        ->where('wiki_pages.title', 'like', $like)
                        ->orWhere('wiki_pages.slug', 'like', $like)
                        ->orWhere('wiki_revisions.content_markdown', 'like', $like);
                });
            })
            ->select([
                'wiki_pages.id as page_id',
                'wiki_pages.slug',
                'wiki_pages.title',
                'wiki_pages.page_type',
                'wiki_pages.source_status as page_source_status',
                'wiki_revisions.id as revision_id',
                'wiki_revisions.source_type',
                'wiki_revisions.source_status',
                'wiki_revisions.content_markdown',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at',
            ])
            ->orderByDesc('wiki_pages.updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->revision_id,
                'domain' => 'wiki',
                'source' => 'wiki_revision',
                'kind' => 'wiki',
                'summary' => (string) $row->title,
                'page_id' => (string) $row->page_id,
                'page_slug' => (string) $row->slug,
                'page_type' => (string) $row->page_type,
                'source_type' => (string) $row->source_type,
                'source_status' => (string) $row->source_status,
                'payload_excerpt' => $this->excerpt((string) $row->content_markdown),
                'evidence_count' => count($this->decodeList($row->evidence_refs)),
                'occurred_at' => (string) $row->created_at,
            ])
            ->all();
    }

    /**
     * @return array{logbook: int, wiki: int, agent_notes: int}
     */
    private function domainCounts(string $projectId): array
    {
        return [
            'logbook' => DB::table('project_memory_entries')
                ->where('project_id', $projectId)
                ->where(function ($nested): void {
                    $nested->whereNull('agent_key')->orWhere('agent_key', '');
                })
                ->where('kind', '!=', 'agent_note')
                ->whereNotIn('source', ['server_agent', 'hades_agent'])
                ->count(),
            'wiki' => DB::table('wiki_pages')
                ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
                ->where('wiki_pages.project_id', $projectId)
                ->count(),
            'agent_notes' => DB::table('project_memory_entries')
                ->where('project_id', $projectId)
                ->where(function ($nested): void {
                    $nested
                        ->where('kind', 'agent_note')
                        ->orWhereIn('source', ['server_agent', 'hades_agent'])
                        ->orWhereNotNull('agent_key');
                })
                ->count(),
        ];
    }

    private function domain(string $domain): string
    {
        return in_array($domain, ['logbook', 'wiki', 'agent_notes'], true) ? $domain : 'all';
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

    private function excerpt(string $content): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', strip_tags($content)));

        return substr($normalized, 0, 500);
    }

    /**
     * @return list<mixed>
     */
    private function decodeList(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
