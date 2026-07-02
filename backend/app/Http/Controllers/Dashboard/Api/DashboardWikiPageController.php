<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use App\Services\WikiRevisionException;
use App\Services\WikiRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class DashboardWikiPageController extends Controller
{
    use ChecksDashboardRoles;

    private const PAGE_TYPES = ['business', 'technical', 'runbook', 'audit'];

    private const SOURCE_STATUSES = [
        'verified_from_code',
        'developer_provided',
        'ai_generated',
        'needs_verification',
        'stale',
        'conflict_with_code',
    ];

    public function store(
        Request $request,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        WikiRevisionService $wiki,
        string $project,
    ): JsonResponse {
        $this->abortUnlessDashboardMutator($request);

        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }

        $validated = $request->validate([
            'repository_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('repositories', 'id')->where(fn ($query) => $query->where('project_id', $project)),
            ],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9\/-]*$/'],
            'title' => ['required', 'string', 'max:255'],
            'page_type' => ['required', 'string', Rule::in(self::PAGE_TYPES)],
            'source_status' => ['required', 'string', Rule::in(self::SOURCE_STATUSES)],
            'content_markdown' => ['required', 'string', 'min:1'],
            'evidence_refs' => ['sometimes', 'array'],
        ]);

        $repositoryId = $validated['repository_id'] ?? null;
        $duplicate = DB::table('wiki_pages')
            ->where('project_id', $project)
            ->where('slug', $validated['slug'])
            ->when(
                $repositoryId,
                fn ($query, string $id) => $query->where('repository_id', $id),
                fn ($query) => $query->whereNull('repository_id'),
            )
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['slug' => 'A wiki page with this slug already exists for this project scope.']);
        }

        return $this->writeRevision($request, $reader, $wiki, [
            'project_id' => $project,
            'repository_id' => $repositoryId,
            'slug' => $validated['slug'],
            'title' => $validated['title'],
            'page_type' => $validated['page_type'],
            'producer' => 'dashboard_user',
            'source_type' => 'user_manual',
            'source_status' => $validated['source_status'],
            'content_markdown' => $validated['content_markdown'],
            'evidence_refs' => $validated['evidence_refs'] ?? [],
        ], Response::HTTP_CREATED);
    }

    public function update(
        Request $request,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        WikiRevisionService $wiki,
        string $page,
    ): JsonResponse {
        $this->abortUnlessDashboardMutator($request);

        $pageRow = DB::table('wiki_pages')->where('id', $page)->first();
        abort_unless($pageRow, Response::HTTP_NOT_FOUND);

        if ($error = $lifecycle->assertProjectActiveForDashboard((string) $pageRow->project_id)) {
            return $error;
        }

        $revision = $pageRow->current_revision_id
            ? DB::table('wiki_revisions')->where('id', $pageRow->current_revision_id)->first()
            : null;

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'page_type' => ['sometimes', 'required', 'string', Rule::in(self::PAGE_TYPES)],
            'source_status' => ['sometimes', 'required', 'string', Rule::in(self::SOURCE_STATUSES)],
            'content_markdown' => ['sometimes', 'required', 'string', 'min:1'],
            'evidence_refs' => ['sometimes', 'array'],
        ]);

        return $this->writeRevision($request, $reader, $wiki, [
            'project_id' => (string) $pageRow->project_id,
            'repository_id' => $pageRow->repository_id ? (string) $pageRow->repository_id : null,
            'slug' => (string) $pageRow->slug,
            'title' => $validated['title'] ?? (string) $pageRow->title,
            'page_type' => $validated['page_type'] ?? (string) $pageRow->page_type,
            'producer' => 'dashboard_user',
            'source_type' => 'user_manual',
            'source_status' => $validated['source_status'] ?? (string) $pageRow->source_status,
            'content_markdown' => $validated['content_markdown'] ?? (string) ($revision->content_markdown ?? ''),
            'evidence_refs' => $validated['evidence_refs'] ?? json_decode((string) ($revision->evidence_refs ?? '[]'), true, flags: JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeRevision(
        Request $request,
        DashboardApiReader $reader,
        WikiRevisionService $wiki,
        array $payload,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        try {
            $result = $wiki->write($payload, (int) $request->user()->id);
        } catch (WikiRevisionException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($reader->wikiPage($result['wiki_page_id']), $status);
    }

    private function abortUnlessDashboardMutator(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }
}
