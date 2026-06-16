<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ListProjectsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $projects = DB::table('projects')
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
