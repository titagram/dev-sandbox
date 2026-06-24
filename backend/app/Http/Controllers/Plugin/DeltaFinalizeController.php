<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\ArtifactStorageException;
use App\Services\DeltaFinalizeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DeltaFinalizeController extends Controller
{
    public function __construct(
        private readonly DeltaFinalizeService $finalize,
        private readonly ProjectLifecycleService $lifecycle,
    )
    {
    }

    public function __invoke(string $deltaSync): JsonResponse
    {
        if ($error = $this->lifecycle->pluginDeltaWriteGuard($deltaSync)) {
            return $error;
        }

        try {
            return response()->json($this->finalize->finalize($deltaSync));
        } catch (ArtifactStorageException $exception) {
            $status = match ($exception->errorCode) {
                'secret_scan_blocked' => Response::HTTP_FORBIDDEN,
                'artifact_chunk_missing', 'artifact_hash_mismatch' => Response::HTTP_UNPROCESSABLE_ENTITY,
                default => Response::HTTP_BAD_REQUEST,
            };

            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $status);
        }
    }
}
