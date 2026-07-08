<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WikiShowController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, string $page): Response
    {
        $pageRow = DB::table('wiki_pages')
            ->leftJoin('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->select([
                'wiki_pages.id',
                'wiki_pages.project_id',
                'wiki_pages.repository_id',
                'wiki_pages.slug',
                'wiki_pages.title',
                'wiki_pages.page_type',
                'wiki_pages.source_status',
                'wiki_pages.updated_at',
                'wiki_revisions.id as revision_id',
                'wiki_revisions.source_type',
                'wiki_revisions.content_markdown',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at as revision_created_at',
            ])
            ->where('wiki_pages.id', $page)
            ->firstOrFail();

        $evidenceRefs = $pageRow->evidence_refs
            ? json_decode($pageRow->evidence_refs, true, 512, JSON_THROW_ON_ERROR)
            : [];

        return Inertia::render('Wiki/Show', [
            'page' => [
                'id' => $pageRow->id,
                'project_id' => $pageRow->project_id,
                'repository_id' => $pageRow->repository_id,
                'slug' => $pageRow->slug,
                'title' => $pageRow->title,
                'page_type' => $pageRow->page_type,
                'source_status' => $pageRow->source_status,
                'updated_at' => $pageRow->updated_at,
            ],
            'revision' => [
                'id' => $pageRow->revision_id,
                'source_type' => $pageRow->source_type ?? 'user_manual',
                'content_markdown' => $pageRow->content_markdown ?? '',
                'evidence_refs' => $evidenceRefs,
                'created_at' => $pageRow->revision_created_at,
            ],
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $pageRow->project_id),
            ],
        ]);
    }
}
