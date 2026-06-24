<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\ArtifactStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeltaStartController extends Controller
{
    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly ProjectLifecycleService $lifecycle,
    )
    {
    }

    public function __invoke(Request $request, string $run): JsonResponse
    {
        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow && $runRow->repository_id, 404);

        if ($error = $this->lifecycle->pluginRunWriteGuard($run)) {
            return $error;
        }

        $validated = $request->validate([
            'local_workspace_id' => ['required', 'string', 'exists:local_workspaces,id'],
            'base_snapshot_id' => ['required', 'string', 'exists:snapshots,id'],
            'branch' => ['required', 'string', 'max:255'],
            'base_sha' => ['required', 'string', 'max:255'],
            'head_sha' => ['nullable', 'string', 'max:255'],
            'dirty_status' => ['required', 'string', 'max:64'],
            'manifest' => ['required', 'array'],
            'manifest.changed_file_count' => ['nullable', 'integer', 'min:0'],
            'manifest.changed_files' => ['nullable', 'array'],
            'manifest.risk_report' => ['nullable', 'array'],
            'manifest.risk_report.risk_level' => ['nullable', 'string'],
            'manifest.artifacts' => ['required', 'array'],
            'manifest.artifacts.*.artifact_id' => ['required', 'string'],
            'manifest.artifacts.*.artifact_type' => ['required', 'string'],
            'manifest.artifacts.*.sha256' => ['required', 'string'],
            'manifest.artifacts.*.size_bytes' => ['required', 'integer'],
            'manifest.artifacts.*.mime_type' => ['nullable', 'string'],
            'manifest.artifacts.*.schema_version' => ['nullable', 'string'],
            'manifest.artifacts.*.producer' => ['nullable', 'string'],
            'manifest.artifacts.*.chunk_count' => ['required', 'integer', 'min:1'],
        ]);

        $deltaId = (string) Str::ulid();
        $artifactRows = [];
        $now = now();

        foreach ($validated['manifest']['artifacts'] as $artifact) {
            $artifactId = $artifact['artifact_id'];
            DB::table('artifacts')->insert([
                'id' => $artifactId,
                'project_id' => $runRow->project_id,
                'repository_id' => $runRow->repository_id,
                'run_id' => $run,
                'artifact_type' => $artifact['artifact_type'],
                'storage_path' => $this->storage->artifactPath($deltaId, $artifactId, 'delta'),
                'sha256' => str_starts_with($artifact['sha256'], 'sha256:') ? substr($artifact['sha256'], 7) : $artifact['sha256'],
                'size_bytes' => $artifact['size_bytes'],
                'mime_type' => $artifact['mime_type'] ?? 'application/json',
                'schema_version' => $artifact['schema_version'] ?? 'v1',
                'status' => 'uploading',
                'producer' => $artifact['producer'] ?? 'devboard-python-plugin',
                'metadata' => json_encode($artifact, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $artifactRows[] = $artifact;
        }

        $changedFileCount = $validated['manifest']['changed_file_count']
            ?? count($validated['manifest']['changed_files'] ?? []);
        $riskLevel = $validated['manifest']['risk_report']['risk_level'] ?? 'low';

        DB::table('delta_syncs')->insert([
            'id' => $deltaId,
            'project_id' => $runRow->project_id,
            'repository_id' => $runRow->repository_id,
            'local_workspace_id' => $validated['local_workspace_id'],
            'run_id' => $run,
            'status' => 'uploading',
            'base_snapshot_id' => $validated['base_snapshot_id'],
            'new_snapshot_id' => null,
            'branch' => $validated['branch'],
            'base_sha' => $validated['base_sha'],
            'head_sha' => $validated['head_sha'] ?? null,
            'dirty_status' => $validated['dirty_status'],
            'changed_file_count' => $changedFileCount,
            'risk_level' => $riskLevel,
            'started_at' => $now,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $run,
            'event_type' => 'delta.started',
            'severity' => 'info',
            'message' => 'Delta sync upload started.',
            'payload' => json_encode([
                'delta_id' => $deltaId,
                'base_snapshot_id' => $validated['base_snapshot_id'],
                'changed_file_count' => $changedFileCount,
                'risk_level' => $riskLevel,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        return response()->json([
            'delta_id' => $deltaId,
            'status' => 'uploading',
            'artifacts' => $artifactRows,
        ]);
    }
}
