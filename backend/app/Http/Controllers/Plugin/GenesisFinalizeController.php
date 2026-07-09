<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\ArtifactStorageException;
use App\Services\AuditLogger;
use App\Services\GenesisFinalizeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GenesisFinalizeController extends Controller
{
    public function __construct(
        private readonly GenesisFinalizeService $finalize,
        private readonly ProjectLifecycleService $lifecycle,
    )
    {
    }

    public function __invoke(Request $request, string $genesisImport): JsonResponse
    {
        if ($error = $this->lifecycle->pluginGenesisWriteGuard($genesisImport)) {
            return $error;
        }

        try {
            return response()->json($this->finalize->finalize(
                $genesisImport,
                $request->boolean('allow_blocked_security_findings'),
            ));
        } catch (ArtifactStorageException $exception) {
            $status = match ($exception->errorCode) {
                'secret_scan_blocked' => Response::HTTP_FORBIDDEN,
                'artifact_chunk_missing', 'artifact_hash_mismatch' => Response::HTTP_UNPROCESSABLE_ENTITY,
                default => Response::HTTP_BAD_REQUEST,
            };

            app(AuditLogger::class)->record('artifact.rejected', 'genesis_import', $genesisImport, [
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], ['type' => 'plugin']);

            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $status);
        }
    }
}
