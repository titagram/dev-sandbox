<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ArtifactsIndexController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request): Response
    {
        $project = DB::table('projects')->orderBy('created_at')->first();

        $artifacts = DB::table('artifacts')
            ->leftJoin('runs', 'runs.id', '=', 'artifacts.run_id')
            ->leftJoin('repositories', 'repositories.id', '=', 'artifacts.repository_id')
            ->select([
                'artifacts.id',
                'artifacts.run_id',
                'artifacts.artifact_type',
                'artifacts.sha256',
                'artifacts.size_bytes',
                'artifacts.mime_type',
                'artifacts.status',
                'artifacts.created_at',
                'artifacts.producer',
                'runs.status as run_status',
                'repositories.name as repository_name',
            ])
            ->orderByDesc('artifacts.created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $artifact): array => [
                'id' => $artifact->id,
                'run_id' => $artifact->run_id,
                'artifact_type' => $artifact->artifact_type,
                'sha256' => $artifact->sha256,
                'size_bytes' => $artifact->size_bytes,
                'mime_type' => $artifact->mime_type,
                'status' => $artifact->status,
                'producer' => $artifact->producer,
                'repository_name' => $artifact->repository_name,
                'source_label' => 'local_plugin_snapshot',
                'run' => $artifact->run_id ? [
                    'id' => $artifact->run_id,
                    'status' => $artifact->run_status,
                    'href' => "/runs/{$artifact->run_id}",
                ] : null,
                'download_href' => $artifact->run_id && in_array($artifact->status, ['validated', 'imported'], true)
                    ? "/runs/{$artifact->run_id}/artifacts/{$artifact->id}/download"
                    : null,
                'created_at' => $artifact->created_at,
            ]);

        return Inertia::render('Artifacts/Index', [
            'project' => $project,
            'artifacts' => $artifacts,
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $project?->id),
            ],
            'summary' => [
                'total_artifacts' => $artifacts->count(),
                'downloadable_artifacts' => $artifacts->whereNotNull('download_href')->count(),
                'failed_artifacts' => $artifacts->whereIn('status', ['failed', 'rejected'])->count(),
            ],
        ]);
    }
}
