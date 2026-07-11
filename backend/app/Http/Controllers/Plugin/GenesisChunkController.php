<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\ArtifactStorageException;
use App\Services\ArtifactStorageService;
use App\Services\PluginInvariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class GenesisChunkController extends Controller
{
    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginInvariantService $invariants,
    ) {}

    public function __invoke(Request $request, string $genesisImport, string $artifact, string $chunk): JsonResponse
    {
        if ($error = $this->lifecycle->pluginGenesisWriteGuard($genesisImport)) {
            return $error;
        }

        $chunkIndex = filter_var($chunk, FILTER_VALIDATE_INT);
        if ($chunkIndex === false || $chunkIndex < 0) {
            return $this->error('artifact_chunk_out_of_range', 'Chunk index must be a non-negative integer within 0..chunk_count-1.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $import = DB::table('genesis_imports')->where('id', $genesisImport)->first();
        $run = DB::table('runs')->where('id', $import->run_id)->first();
        $artifactRow = DB::table('artifacts')->where('id', $artifact)->first();
        abort_unless($run && $artifactRow, 404);

        if ($error = $this->invariants->assertRunOwnership($request, $run)) {
            return $error;
        }

        if ($error = $this->invariants->assertArtifactBelongsToTransfer(
            $artifactRow,
            $import,
            $genesisImport,
            'genesis',
        )) {
            return $error;
        }

        if (strlen($request->getContent()) > config('devboard.artifacts.max_chunk_bytes')) {
            return $this->error('artifact_chunk_too_large', 'Chunk body exceeds max_chunk_bytes.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $this->storage->storeChunk(
                $genesisImport,
                $artifact,
                $chunkIndex,
                $request->getContent(),
                (string) $request->header('X-DevBoard-Chunk-SHA256'),
            );
        } catch (ArtifactStorageException $exception) {
            return $this->error(
                $exception->errorCode,
                $exception->getMessage(),
                $this->mapStatus($exception->errorCode),
            );
        }

        return response()->json([
            'artifact_id' => $artifact,
            'chunk_index' => $chunkIndex,
            'status' => 'received',
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function mapStatus(string $errorCode): int
    {
        return match ($errorCode) {
            'artifact_chunk_out_of_range', 'artifact_size_mismatch' => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_CONFLICT,
        };
    }
}
