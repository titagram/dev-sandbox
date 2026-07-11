<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\PluginProjectScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListRepositoriesController extends Controller
{
    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginProjectScope $projectScope,
    ) {}

    public function __invoke(Request $request, string $project): JsonResponse
    {
        if ($error = $this->projectScope->authorize($request, $project)) {
            return $error;
        }

        if ($error = $this->lifecycle->pluginProjectWriteGuard($project)) {
            return $error;
        }

        $repositories = DB::table('repositories')
            ->where('project_id', $project)
            ->orderBy('name')
            ->get()
            ->map(fn (object $repository): array => [
                'repository_id' => $repository->id,
                'project_id' => $repository->project_id,
                'name' => $repository->name,
                'slug' => $repository->slug,
                'default_branch' => $repository->default_branch,
                'git_mode' => $repository->local_only ? 'local_only' : 'remote_enabled',
                'code_exposure' => $repository->code_exposure_policy,
                'graph_required' => (bool) $repository->graph_enabled,
            ])
            ->all();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $project,
            'repositories' => $repositories,
        ]);
    }
}
