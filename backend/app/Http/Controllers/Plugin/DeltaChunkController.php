<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\ArtifactStorageException;
use App\Services\ArtifactStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DeltaChunkController extends Controller
{
    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly ProjectLifecycleService $lifecycle,
    )
    {
    }

    public function __invoke(Request $request, string $deltaSync, string $artifact, int $chunk): JsonResponse
    {
        if ($error = $this->lifecycle->pluginDeltaWriteGuard($deltaSync)) {
            return $error;
        }

        abort_unless(DB::table('artifacts')->where('id', $artifact)->exists(), 404);

        if (strlen($request->getContent()) > config('devboard.artifacts.max_chunk_bytes')) {
            return response()->json([
                'error' => ['code' => 'artifact_chunk_too_large', 'message' => 'Chunk body exceeds max_chunk_bytes.'],
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $this->storage->storeChunk(
                $deltaSync,
                $artifact,
                $chunk,
                $request->getContent(),
                (string) $request->header('X-DevBoard-Chunk-SHA256'),
                'delta',
            );
        } catch (ArtifactStorageException $exception) {
            return response()->json([
                'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'artifact_id' => $artifact,
            'chunk_index' => $chunk,
            'status' => 'received',
        ]);
    }
}
