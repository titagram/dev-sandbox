<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plugin\StartDeltaSyncRequest;
use App\Projects\ProjectLifecycleService;
use App\Services\ArtifactStorageService;
use App\Services\PluginInvariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeltaStartController extends Controller
{
    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginInvariantService $invariants,
    ) {}

    public function __invoke(StartDeltaSyncRequest $request, string $run): JsonResponse
    {
        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow && $runRow->repository_id, 404);

        if ($error = $this->lifecycle->pluginRunWriteGuard($run)) {
            return $error;
        }

        $validated = $request->validated();
        $workspace = DB::table('local_workspaces')->where('id', $validated['local_workspace_id'])->first();
        $baseSnapshot = DB::table('snapshots')->where('id', $validated['base_snapshot_id'])->first();

        if ($error = $this->invariants->assertRunOwnership($request, $runRow)) {
            return $error;
        }

        if ($error = $this->invariants->assertReferences(
            (string) $runRow->local_workspace_id === (string) $workspace->id
                && (string) $workspace->repository_id === (string) $runRow->repository_id
                && (string) $workspace->device_id === (string) $runRow->device_id
                && (string) $baseSnapshot->project_id === (string) $runRow->project_id
                && (string) $baseSnapshot->repository_id === (string) $runRow->repository_id
                && (string) $baseSnapshot->local_workspace_id === (string) $workspace->id,
            'Delta project, repository, workspace, snapshot, run, and device references are inconsistent.',
        )) {
            return $error;
        }

        $deltaId = (string) Str::ulid();
        $artifactRows = [];
        $now = now();

        $changedFileCount = $validated['manifest']['changed_file_count']
            ?? count($validated['manifest']['changed_files'] ?? []);
        $riskLevel = $validated['manifest']['risk_report']['risk_level'] ?? 'low';

        DB::transaction(function () use ($validated, $runRow, $run, $deltaId, &$artifactRows, $now, $changedFileCount, $riskLevel): void {
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
        });

        return response()->json([
            'delta_id' => $deltaId,
            'status' => 'uploading',
            'artifacts' => $artifactRows,
        ]);
    }
}
