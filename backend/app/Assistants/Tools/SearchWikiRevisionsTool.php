<?php

namespace App\Assistants\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class SearchWikiRevisionsTool implements Tool
{
    public function name(): string
    {
        return 'search_wiki_revisions';
    }

    public function description(): Stringable|string
    {
        return 'Search current DevBoard wiki revisions for a project and return bounded excerpts with source status and evidence refs.';
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
        $query = trim((string) ($arguments['query'] ?? ''));
        $limit = min(10, max(1, (int) ($arguments['limit'] ?? 5)));

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
                'results' => [],
            ];
        }

        $wikiQuery = DB::table('wiki_pages')
            ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->where('wiki_pages.project_id', $projectId)
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
            ]);

        if ($query !== '') {
            $like = '%'.$query.'%';
            $wikiQuery->where(function ($nested) use ($like): void {
                $nested
                    ->where('wiki_pages.title', 'like', $like)
                    ->orWhere('wiki_pages.slug', 'like', $like)
                    ->orWhere('wiki_revisions.content_markdown', 'like', $like);
            });
        }

        $results = $wikiQuery
            ->orderByDesc('wiki_pages.updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'page_id' => (string) $row->page_id,
                'revision_id' => (string) $row->revision_id,
                'slug' => (string) $row->slug,
                'title' => (string) $row->title,
                'page_type' => (string) $row->page_type,
                'page_source_status' => (string) $row->page_source_status,
                'revision_source_status' => (string) $row->revision_source_status,
                'source_type' => (string) $row->source_type,
                'excerpt' => $this->excerpt((string) $row->content_markdown, $query),
                'evidence_refs' => $this->decodeJsonList($row->evidence_refs),
                'updated_at' => (string) $row->updated_at,
                'revision_created_at' => (string) $row->revision_created_at,
            ])
            ->all();

        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'found' => $results !== [],
            'query' => $query,
            'limit' => $limit,
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
                ->description('The DevBoard project ULID whose wiki should be searched.')
                ->required(),
            'query' => $schema->string()
                ->description('The text to search for in current wiki page titles, slugs, and revision content.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of wiki results to return, capped at 10.')
                ->min(1)
                ->max(10),
        ];
    }

    private function excerpt(string $content, string $query): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', strip_tags($content)));

        if ($normalized === '') {
            return '';
        }

        $position = $query === '' ? false : stripos($normalized, $query);
        $start = $position === false ? 0 : max(0, $position - 80);
        $excerpt = Str::substr($normalized, $start, 280);

        if ($start > 0) {
            $excerpt = '...'.$excerpt;
        }

        if (Str::length($normalized) > $start + Str::length($excerpt)) {
            $excerpt .= '...';
        }

        return $excerpt;
    }

    /**
     * @return list<mixed>
     */
    private function decodeJsonList(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
