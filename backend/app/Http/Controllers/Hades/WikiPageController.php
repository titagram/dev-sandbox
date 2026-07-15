<?php

namespace App\Http\Controllers\Hades;

use App\Enums\SourceStatus;
use App\Http\Controllers\Controller;
use App\Services\Hades\HadesTokenException;
use App\Services\Hades\HadesWikiCapability;
use App\Services\Hades\WikiVerificationService;
use App\Services\WikiRevisionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
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

    private const MAX_EVIDENCE_REFS = 80;

    private const PAGE_TYPES = ['business', 'technical', 'runbook', 'audit'];

    public function __construct(
        private readonly WikiRevisionService $wiki,
        private readonly HadesWikiCapability $capability,
        private readonly WikiVerificationService $verification,
    ) {}

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

        $paginator = $this->currentRevisionListQuery($validated['project_id'])
            ->when(
                ($validated['source_status'] ?? null) !== null,
                fn ($query) => $query->where('wiki_revisions.source_status', $validated['source_status']),
            )
            ->orderByDesc('wiki_pages.updated_at')
            ->orderByDesc('wiki_pages.id')
            ->cursorPaginate((int) ($validated['limit'] ?? 20));

        $items = $paginator->getCollection()
            ->map(fn (object $page): array => $this->listPagePayload($page))
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

        $wikiPage = $this->currentRevisionDetailQuery($validated['project_id'])
            ->where('wiki_pages.id', $page)
            ->first();

        if (! $wikiPage) {
            return $this->error('wiki_page_not_found', 'Hades wiki page was not found.', Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'wiki_page' => $this->detailPagePayload($wikiPage),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9\/-]*$/'],
            'title' => ['required', 'string', 'max:255'],
            'page_type' => ['required', 'string', Rule::in(self::PAGE_TYPES)],
            'content_markdown' => ['required', 'string', 'min:1', 'max:'.self::MAX_MARKDOWN_CHARACTERS],
            'evidence_refs' => ['sometimes', 'array', 'list', 'max:'.self::MAX_EVIDENCE_REFS],
            'evidence_refs.*' => ['required', 'array:kind,schema,sha256,hash,path,bytes,raw_source_included'],
            'evidence_refs.*.kind' => ['required', 'string', 'max:64'],
            'evidence_refs.*.schema' => ['sometimes', 'string', 'max:191'],
            'evidence_refs.*.sha256' => ['sometimes', 'string', 'size:64'],
            'evidence_refs.*.hash' => ['sometimes', 'string', 'size:64'],
            'evidence_refs.*.path' => ['sometimes', 'string', 'max:2048'],
            'evidence_refs.*.bytes' => ['sometimes', 'integer', 'min:0'],
            'evidence_refs.*.raw_source_included' => ['sometimes', 'boolean'],
            'source_status' => ['prohibited'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding(
            $agent,
            $validated['project_id'],
            $validated['workspace_binding_id'],
        );

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        try {
            $this->capability->assertCanWrite($agent);
        } catch (HadesTokenException $exception) {
            return $exception->toResponse();
        }

        $result = $this->wiki->write([
            'project_id' => $validated['project_id'],
            'repository_id' => null,
            'slug' => $validated['slug'],
            'title' => $validated['title'],
            'page_type' => $validated['page_type'],
            'producer' => 'hades',
            'source_type' => 'hades_agent_draft',
            'source_status' => SourceStatus::NeedsVerification->value,
            'content_markdown' => $validated['content_markdown'],
            'evidence_refs' => $validated['evidence_refs'] ?? [],
        ]);

        return response()->json(
            $result,
            $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function verify(Request $request, string $page): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'expected_current_revision_id' => ['required', 'string'],
            'evidence_refs' => ['present', 'array', 'list', 'max:'.self::MAX_EVIDENCE_REFS],
            'evidence_refs.*' => ['required', 'array:kind,schema,sha256,hash,path'],
            'evidence_refs.*.kind' => ['required', 'string', 'max:64'],
            'evidence_refs.*.schema' => ['sometimes', 'string', 'max:191'],
            'evidence_refs.*.sha256' => ['sometimes', 'string', 'max:64'],
            'evidence_refs.*.hash' => ['sometimes', 'string', 'max:64'],
            'evidence_refs.*.path' => ['sometimes', 'string', 'max:2048'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding(
            $agent,
            $validated['project_id'],
            $validated['workspace_binding_id'],
        );

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        try {
            $this->capability->assertCanWrite($agent);
            $result = $this->verification->verify(
                $validated['project_id'],
                $binding->id,
                $page,
                $validated['expected_current_revision_id'],
                $validated['evidence_refs'],
                $agent,
            );
        } catch (HadesTokenException $exception) {
            return $exception->toResponse();
        }

        return response()->json($result, Response::HTTP_OK);
    }

    private function currentRevisionListQuery(string $projectId): Builder
    {
        return $this->currentRevisionQuery($projectId)->select([
            ...$this->currentRevisionColumns(),
            DB::raw($this->evidenceCountExpression().' as evidence_count'),
        ]);
    }

    private function evidenceCountExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CASE json_type(wiki_revisions.evidence_refs)
                WHEN 'array' THEN json_array_length(wiki_revisions.evidence_refs)
                WHEN 'object' THEN (SELECT count(*) FROM json_each(wiki_revisions.evidence_refs))
                ELSE 0 END",
            'pgsql' => "CASE jsonb_typeof(wiki_revisions.evidence_refs::jsonb)
                WHEN 'array' THEN jsonb_array_length(wiki_revisions.evidence_refs::jsonb)
                WHEN 'object' THEN (SELECT count(*) FROM jsonb_object_keys(wiki_revisions.evidence_refs::jsonb))
                ELSE 0 END",
            'mysql', 'mariadb' => "CASE JSON_TYPE(wiki_revisions.evidence_refs)
                WHEN 'ARRAY' THEN JSON_LENGTH(wiki_revisions.evidence_refs)
                WHEN 'OBJECT' THEN JSON_LENGTH(wiki_revisions.evidence_refs)
                ELSE 0 END",
            default => throw new \RuntimeException('Unsupported database driver for wiki evidence counts.'),
        };
    }

    private function currentRevisionDetailQuery(string $projectId): Builder
    {
        return $this->currentRevisionQuery($projectId)->select([
            ...$this->currentRevisionColumns(),
            'wiki_revisions.content_markdown',
            'wiki_revisions.evidence_refs',
        ]);
    }

    private function currentRevisionQuery(string $projectId): Builder
    {
        return DB::table('wiki_pages')
            ->join('wiki_revisions', function (JoinClause $join): void {
                $join->on('wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
                    ->on('wiki_revisions.wiki_page_id', '=', 'wiki_pages.id');
            })
            ->where('wiki_pages.project_id', $projectId);
    }

    /**
     * @return list<string>
     */
    private function currentRevisionColumns(): array
    {
        return [
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
            'wiki_revisions.created_at as revision_created_at',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listPagePayload(object $page): array
    {
        return array_merge($this->pagePayload($page), [
            'evidence_count' => (int) $page->evidence_count,
            'updated_at' => $this->toIsoString($page->updated_at),
            'revision_created_at' => $this->toIsoString($page->revision_created_at),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPagePayload(object $page): array
    {
        $markdown = (string) $page->content_markdown;

        return array_merge($this->pagePayload($page), [
            'content_markdown' => Str::substr($markdown, 0, self::MAX_MARKDOWN_CHARACTERS),
            'content_truncated' => Str::length($markdown) > self::MAX_MARKDOWN_CHARACTERS,
            'evidence_refs' => array_slice($this->evidenceRefs($page->evidence_refs), 0, 80),
            'updated_at' => $this->toIsoString($page->updated_at),
            'revision_created_at' => $this->toIsoString($page->revision_created_at),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pagePayload(object $page): array
    {
        return [
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
    }

    /**
     * @return list<mixed>
     */
    private function evidenceRefs(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values($decoded) : [];
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
