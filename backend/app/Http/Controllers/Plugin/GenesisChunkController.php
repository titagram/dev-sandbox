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

class GenesisChunkController extends Controller
{
    public function __construct(
        private readonly ArtifactStorageService $storage,
        private readonly ProjectLifecycleService $lifecycle,
    )
    {
    }

    public function __invoke(Request $request, string $genesisImport, string $artifact, int $chunk): JsonResponse
    {
        if ($error = $this->lifecycle->pluginGenesisWriteGuard($genesisImport)) {
            return $error;
        }

        abort_unless(DB::table('artifacts')->where('id', $artifact)->exists(), 404);

        try {
            $this->storage->storeChunk(
                $genesisImport,
                $artifact,
                $chunk,
                $request->getContent(),
                (string) $request->header('X-DevBoard-Chunk-SHA256'),
            );
        } catch (ArtifactStorageException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return response()->json([
            'artifact_id' => $artifact,
            'chunk_index' => $chunk,
            'status' => 'received',
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
