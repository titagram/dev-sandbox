<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectShowController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, string $project): Response
    {
        $projectRow = DB::table('projects')->where('id', $project)->firstOrFail();
        $repositories = DB::table('repositories')->where('project_id', $project)->orderBy('name')->get()->map(function (object $repository) {
            $genesis = DB::table('genesis_imports')->where('repository_id', $repository->id)->orderByDesc('created_at')->first();
            $delta = DB::table('delta_syncs')->where('repository_id', $repository->id)->orderByDesc('created_at')->first();
            $latestRun = DB::table('runs')->where('repository_id', $repository->id)->orderByDesc('created_at')->first();
            $latestSnapshot = DB::table('snapshots')->where('repository_id', $repository->id)->orderByDesc('created_at')->first();
            $graphArtifact = DB::table('artifacts')
                ->where('repository_id', $repository->id)
                ->where('artifact_type', 'graph_snapshot')
                ->orderByDesc('created_at')
                ->first();
            $staleWikiCount = DB::table('wiki_pages')
                ->where('repository_id', $repository->id)
                ->whereIn('source_status', ['stale', 'conflict_with_code'])
                ->count();
            $wikiCount = DB::table('wiki_pages')->where('repository_id', $repository->id)->count();

            return [
                'repository_id' => $repository->id,
                'name' => $repository->name,
                'default_branch' => $repository->default_branch,
                'git_mode' => $repository->local_only ? 'local_only' : 'remote_enabled',
                'last_local_snapshot' => $latestSnapshot?->created_at,
                'genesis_status' => $genesis?->status ?? 'none',
                'delta_status' => $delta?->status ?? 'none',
                'graph_status' => $graphArtifact ? ($graphArtifact->status === 'imported' ? 'ready' : $graphArtifact->status) : ($genesis?->status === 'active' ? 'pending_import' : 'none'),
                'wiki_status' => $wikiCount === 0 ? 'none' : ($staleWikiCount > 0 ? "{$staleWikiCount} stale" : 'current'),
                'risk_level' => $latestRun?->risk_level ?? 'low',
                'latest_run' => $latestRun?->id,
                'source_label' => 'local_plugin_snapshot',
            ];
        });

        return Inertia::render('Projects/Show', [
            'project' => $projectRow,
            'repositories' => $repositories,
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $projectRow->id),
            ],
            'policySummary' => [
                'workspace' => 'implicit',
                'git_mode' => 'local_only',
                'code_exposure_policy' => $projectRow->default_code_exposure_policy,
            ],
            'recentRuns' => DB::table('runs')
                ->where('project_id', $project)
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn (object $run) => [
                    'id' => $run->id,
                    'status' => $run->status,
                    'risk_level' => $run->risk_level,
                    'summary' => $run->summary,
                    'source_label' => 'local_plugin_snapshot',
                    'created_at' => $run->created_at,
                ]),
            'artifacts' => DB::table('artifacts')->where('project_id', $project)->orderByDesc('created_at')->limit(8)->get(),
            'wikiPages' => DB::table('wiki_pages')
                ->leftJoin('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
                ->where('wiki_pages.project_id', $project)
                ->select([
                    'wiki_pages.id',
                    'wiki_pages.slug',
                    'wiki_pages.title',
                    'wiki_pages.page_type',
                    'wiki_pages.source_status',
                    'wiki_pages.updated_at',
                    'wiki_revisions.source_type',
                    'wiki_revisions.evidence_refs',
                ])
                ->orderBy('wiki_pages.title')
                ->limit(6)
                ->get()
                ->map(fn (object $page) => [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'page_type' => $page->page_type,
                    'source_status' => $page->source_status,
                    'source_type' => $page->source_type ?? 'user_manual',
                    'evidence_refs' => $page->evidence_refs ? json_decode($page->evidence_refs, true, 512, JSON_THROW_ON_ERROR) : [],
                    'updated_at' => $page->updated_at,
                ]),
        ]);
    }
}
