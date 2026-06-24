<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RepositoryInstructionsController extends Controller
{
    public function __construct(private readonly ProjectLifecycleService $lifecycle)
    {
    }

    public function __invoke(string $repository): JsonResponse
    {
        $repositoryRow = DB::table('repositories')
            ->join('projects', 'projects.id', '=', 'repositories.project_id')
            ->where('repositories.id', $repository)
            ->select([
                'repositories.id',
                'repositories.name',
                'repositories.slug',
                'repositories.default_branch',
                'repositories.local_only',
                'repositories.code_exposure_policy',
                'repositories.graph_enabled',
                'projects.id as project_id',
                'projects.name as project_name',
                'projects.slug as project_slug',
            ])
            ->first();

        abort_unless($repositoryRow, 404);

        if ($error = $this->lifecycle->pluginProjectWriteGuard((string) $repositoryRow->project_id)) {
            return $error;
        }

        return response()->json([
            'protocol_version' => 'v1',
            'repository_id' => $repositoryRow->id,
            'project_id' => $repositoryRow->project_id,
            'source_status' => 'developer_provided',
            'instructions' => [
                [
                    'key' => 'git_mode',
                    'body' => 'Treat this repository as local_only. Do not infer pushed branches, pull requests, or merge state.',
                ],
                [
                    'key' => 'source_truth',
                    'body' => 'Label local plugin facts as local_plugin_snapshot until the backend imports and consolidates artifacts.',
                ],
                [
                    'key' => 'security',
                    'body' => 'Block secrets and credentials from artifacts, wiki revisions, logs, generated files, and instructions.',
                ],
            ],
            'context' => [
                'project' => [
                    'project_id' => $repositoryRow->project_id,
                    'name' => $repositoryRow->project_name,
                    'slug' => $repositoryRow->project_slug,
                ],
                'repository' => [
                    'repository_id' => $repositoryRow->id,
                    'name' => $repositoryRow->name,
                    'slug' => $repositoryRow->slug,
                    'default_branch' => $repositoryRow->default_branch,
                    'git_mode' => $repositoryRow->local_only ? 'local_only' : 'remote_enabled',
                    'code_exposure' => $repositoryRow->code_exposure_policy,
                    'graph_required' => (bool) $repositoryRow->graph_enabled,
                ],
            ],
        ]);
    }
}
