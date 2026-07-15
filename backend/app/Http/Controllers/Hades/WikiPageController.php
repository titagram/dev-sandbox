<?php

namespace App\Http\Controllers\Hades;

use App\Enums\SourceStatus;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class WikiPageController extends Controller
{
    private const MAX_MARKDOWN_CHARACTERS = 24000;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'source_status' => ['nullable', Rule::enum(SourceStatus::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $binding = $this->linkedBinding(
            $auth['agent'],
            $validated['project_id'],
            $validated['workspace_binding_id'],
        );

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $paginator = $this->currentRevisionQuery($validated['project_id'])
            ->when(
                ($validated['source_status'] ?? null) !== null,
                fn ($query) => $query->where('wiki_pages.source_status', $validated['source_status']),
            )
            ->orderByDesc('wiki_pages.updated_at')
            ->orderByDesc('wiki_pages.id')
            ->cursorPaginate((int) ($validated['limit'] ?? 20));

        $items = $paginator->getCollection()
            ->map(fn (object $page): array => $this->pagePayload($page, false))
            ->values()
            ->all();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'items' => $items,
            'next_cursor' => $paginator->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, string $page): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $binding = $this->linkedBinding(
            $auth['agent'],
            $validated['project_id'],
            $validated['workspace_binding_id'],
        );

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $wikiPage = $this->currentRevisionQuery($validated['project_id'])
            ->where('wiki_pages.id', $page)
            ->first();

        if (! $wikiPage) {
            return $this->error('wiki_page_not_found', 'Hades wiki page was not found.', Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'wiki_page' => $this->pagePayload($wikiPage, true),
        ]);
    }

    private function currentRevisionQuery(string $projectId): Builder
    {
        return DB::table('wiki_pages')
            ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->where('wiki_pages.project_id', $projectId)
            ->select([
                'wiki_pages.id',
                'wiki_pages.project_id',
                'wiki_pages.repository_id',
                'wiki_pages.slug',
                'wiki_pages.title',
                'wiki_pages.page_type',
                'wiki_pages.current_revision_id',
                'wiki_pages.updated_at',
                'wiki_revisions.id as revision_id',
                'wiki_revisions.producer',
                'wiki_revisions.source_type',
                'wiki_revisions.source_status',
                'wiki_revisions.content_markdown',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at as revision_created_at',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pagePayload(object $page, bool $includeMarkdown): array
    {
        $evidenceRefs = $this->evidenceRefs($page->evidence_refs);
        $payload = [
            'id' => $page->id,
            'project_id' => $page->project_id,
            'repository_id' => $page->repository_id,
            'slug' => $page->slug,
            'title' => $page->title,
            'page_type' => $page->page_type,
            'current_revision_id' => $page->current_revision_id,
            'revision_id' => $page->revision_id,
            'producer' => $page->producer,
            'source_type' => $page->source_type,
            'source_status' => $page->source_status,
        ];

        if ($includeMarkdown) {
            $markdown = (string) $page->content_markdown;
            $payload['content_markdown'] = Str::substr($markdown, 0, self::MAX_MARKDOWN_CHARACTERS);
            $payload['content_truncated'] = Str::length($markdown) > self::MAX_MARKDOWN_CHARACTERS;
            $payload['evidence_refs'] = $evidenceRefs;
        } else {
            $payload['evidence_count'] = count($evidenceRefs);
        }

        $payload['updated_at'] = $this->toIsoString($page->updated_at);
        $payload['revision_created_at'] = $this->toIsoString($page->revision_created_at);

        return $payload;
    }

    /**
     * @return list<mixed>
     */
    private function evidenceRefs(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_slice(array_values($decoded), 0, 80) : [];
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

    private function toIsoString(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
