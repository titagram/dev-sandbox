<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RepositoryPolicyController extends Controller
{
    public function __invoke(string $repository): JsonResponse
    {
        $repositoryRow = DB::table('repositories')->where('id', $repository)->first();

        abort_unless($repositoryRow, 404);

        return response()->json([
            'protocol_version' => 'v1',
            'repository_id' => $repositoryRow->id,
            'git_mode' => $repositoryRow->local_only ? 'local_only' : 'remote_enabled',
            'code_exposure' => $repositoryRow->code_exposure_policy,
            'graph_required' => (bool) $repositoryRow->graph_enabled,
            'secret_scan' => [
                'mode' => 'hybrid_block_warn',
                'block_patterns' => ['.env', 'private_key', 'token', 'certificate', 'database_dump'],
                'warn_patterns' => ['vendor', 'cache', 'build', 'generated', 'oversized'],
            ],
            'excluded_paths' => $this->mergedExcludedPaths($repositoryRow),
            'protected_paths' => json_decode($repositoryRow->protected_paths, true, 512, JSON_THROW_ON_ERROR),
            'max_artifact_bytes' => 524_288_000,
            'chunk_size_bytes' => 5_242_880,
        ]);
    }

    /**
     * @return list<string>
     */
    private function mergedExcludedPaths(object $repository): array
    {
        $paths = json_decode($repository->excluded_paths, true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_unique(array_merge($paths, ['.git/', 'storage/uploads/'])));
    }
}
