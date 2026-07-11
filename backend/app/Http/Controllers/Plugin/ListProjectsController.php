<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Services\PluginProjectScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListProjectsController extends Controller
{
    public function __construct(private readonly PluginProjectScope $projectScope) {}

    public function __invoke(Request $request): JsonResponse
    {
        $projectId = $this->projectScope->tokenProjectId($request);
        $projects = DB::table('projects')
            ->where('status', 'active')
            ->when($projectId !== null, fn ($query) => $query->where('id', $projectId))
            ->orderBy('name')
            ->get()
            ->map(fn (object $project): array => [
                'project_id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'default_code_exposure_policy' => $project->default_code_exposure_policy,
            ])
            ->all();

        return response()->json([
            'protocol_version' => 'v1',
            'projects' => $projects,
        ]);
    }
}
