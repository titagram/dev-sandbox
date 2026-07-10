<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plugin\StartGenesisImportRequest;
use App\Projects\ProjectLifecycleService;
use App\Services\ArtifactStorageService;
use App\Services\PluginInvariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenesisStartController extends Controller
{
    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginInvariantService $invariants,
    ) {}

    public function __invoke(StartGenesisImportRequest $request, string $repository): JsonResponse
    {
        $repositoryRow = DB::table('repositories')->where('id', $repository)->first();
        abort_unless($repositoryRow, 404);

        if ($error = $this->lifecycle->pluginRepositoryWriteGuard($repository)) {
            return $error;
        }

        $validated = $request->validated();

        $run = DB::table('runs')->where('id', $validated['run_id'])->first();
        $workspace = DB::table('local_workspaces')->where('id', $validated['local_workspace_id'])->first();

        if ($error = $this->invariants->assertRunOwnership($request, $run)) {
            return $error;
        }

        if ($error = $this->invariants->assertReferences(
            (string) $run->project_id === (string) $repositoryRow->project_id
                && (string) $run->repository_id === $repository
                && (string) $run->local_workspace_id === (string) $workspace->id
                && (string) $workspace->repository_id === $repository
                && (string) $workspace->device_id === (string) $run->device_id,
            'Genesis project, repository, workspace, run, and device references are inconsistent.',
        )) {
            return $error;
        }

        $artifactRows = $validated['manifest']['artifacts'];
        $artifactIds = array_map(
            fn (array $artifact): string => (string) $artifact['artifact_id'],
            $artifactRows,
        );

        $existingImport = DB::table('genesis_imports')
            ->where('repository_id', $repository)
            ->where('run_id', $validated['run_id'])
            ->where('local_workspace_id', $validated['local_workspace_id'])
            ->orderByDesc('created_at')
            ->first();

        if ($existingImport && $this->sameArtifactSet((string) $existingImport->id, $artifactIds)) {
            if (in_array((string) $existingImport->status, ['uploading', 'failed'], true)) {
                DB::table('genesis_imports')->where('id', $existingImport->id)->update([
                    'status' => 'uploading',
                    'finished_at' => null,
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'import_id' => $existingImport->id,
                'status' => (string) DB::table('genesis_imports')->where('id', $existingImport->id)->value('status'),
                'artifacts' => $artifactRows,
            ]);
        }

        $importId = (string) Str::ulid();
        $manifestArtifactId = null;
        $now = now();

        DB::transaction(function () use ($validated, $repositoryRow, $repository, $run, $importId, &$manifestArtifactId, $now): void {
            foreach ($validated['manifest']['artifacts'] as $artifact) {
                $artifactId = $artifact['artifact_id'];
                if ($artifact['artifact_type'] === 'genesis_manifest') {
                    $manifestArtifactId = $artifactId;
                }

                DB::table('artifacts')->insert([
                    'id' => $artifactId,
                    'project_id' => $repositoryRow->project_id,
                    'repository_id' => $repository,
                    'run_id' => $validated['run_id'],
                    'artifact_type' => $artifact['artifact_type'],
                    'storage_path' => $this->storage->artifactPath($importId, $artifactId),
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
            }

            DB::table('genesis_imports')->insert([
                'id' => $importId,
                'project_id' => $repositoryRow->project_id,
                'repository_id' => $repository,
                'local_workspace_id' => $validated['local_workspace_id'],
                'run_id' => $validated['run_id'],
                'status' => 'uploading',
                'manifest_artifact_id' => $manifestArtifactId,
                'snapshot_id' => null,
                'base_branch' => $run->base_branch,
                'base_sha' => $run->base_sha,
                'head_sha' => $run->head_sha ?? $run->base_sha,
                'started_at' => $now,
                'finished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return response()->json([
            'import_id' => $importId,
            'status' => 'uploading',
            'artifacts' => $artifactRows,
        ]);
    }

    /**
     * @param  list<string>  $artifactIds
     */
    private function sameArtifactSet(string $importId, array $artifactIds): bool
    {
        $existingArtifactIds = DB::table('artifacts')
            ->where('storage_path', 'like', "devboard/artifacts/genesis/{$importId}/%/artifact")
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->sort()
            ->values()
            ->all();

        sort($artifactIds);

        return $existingArtifactIds === array_values($artifactIds);
    }
}
