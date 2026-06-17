<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WikiIndexController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request): Response
    {
        $project = DB::table('projects')->orderBy('created_at')->first();

        $pages = DB::table('wiki_pages')
            ->leftJoin('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->leftJoin('repositories', 'repositories.id', '=', 'wiki_pages.repository_id')
            ->select([
                'wiki_pages.id',
                'wiki_pages.project_id',
                'wiki_pages.repository_id',
                'wiki_pages.slug',
                'wiki_pages.title',
                'wiki_pages.page_type',
                'wiki_pages.source_status',
                'wiki_pages.updated_at',
                'wiki_revisions.source_type',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at as revision_created_at',
                'repositories.name as repository_name',
            ])
            ->orderBy('wiki_pages.title')
            ->get()
            ->map(function (object $page): array {
                $evidenceRefs = $page->evidence_refs
                    ? json_decode($page->evidence_refs, true, 512, JSON_THROW_ON_ERROR)
                    : [];

                return [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'page_type' => $page->page_type,
                    'repository_name' => $page->repository_name,
                    'source_status' => $page->source_status,
                    'source_type' => $page->source_type ?? 'user_manual',
                    'evidence_count' => count($evidenceRefs),
                    'updated_at' => $page->updated_at,
                    'revision_created_at' => $page->revision_created_at,
                    'href' => "/wiki/pages/{$page->id}",
                ];
            });

        return Inertia::render('Wiki/Index', [
            'project' => $project,
            'pages' => $pages,
            'sourceLabel' => 'local_plugin_snapshot',
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $project?->id),
            ],
            'summary' => [
                'total_pages' => $pages->count(),
                'technical_pages' => $pages->where('page_type', 'technical')->count(),
                'stale_pages' => $pages->whereIn('source_status', ['stale', 'conflict_with_code'])->count(),
            ],
        ]);
    }
}
