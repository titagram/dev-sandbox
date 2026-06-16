<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Services\ArtifactStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenesisStartController extends Controller
{
    public function __construct(private readonly ArtifactStorageService $storage)
    {
    }

    public function __invoke(Request $request, string $repository): JsonResponse
    {
        $repositoryRow = DB::table('repositories')->where('id', $repository)->first();
        abort_unless($repositoryRow, 404);

        $validated = $request->validate([
            'run_id' => ['required', 'string', 'exists:runs,id'],
            'local_workspace_id' => ['required', 'string', 'exists:local_workspaces,id'],
            'manifest' => ['required', 'array'],
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

        $run = DB::table('runs')->where('id', $validated['run_id'])->first();
        $importId = (string) Str::ulid();
        $artifactRows = [];
        $manifestArtifactId = null;
        $now = now();

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

            $artifactRows[] = $artifact;
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

        return response()->json([
            'import_id' => $importId,
            'status' => 'uploading',
            'artifacts' => $artifactRows,
        ]);
    }
}
