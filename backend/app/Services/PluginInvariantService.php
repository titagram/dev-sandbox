<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PluginInvariantService
{
    public function assertAuthenticatedDevice(Request $request): ?JsonResponse
    {
        $auth = $request->attributes->get('plugin_auth');
        $token = $auth['token'] ?? null;
        $device = $auth['device'] ?? null;

        if (! $token || ! $device
            || $token->device_id === null
            || (string) $token->device_id !== (string) $device->id
            || (int) $token->user_id !== (int) $device->user_id
            || (string) $request->header('X-DevBoard-Device-Id') !== (string) $device->id) {
            return $this->error(
                'device_required',
                'A registered plugin device matching the authenticated token is required.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return null;
    }

    public function assertRunOwnership(Request $request, object $run): ?JsonResponse
    {
        if ($error = $this->assertAuthenticatedDevice($request)) {
            return $error;
        }

        $token = $request->attributes->get('plugin_auth')['token'];

        if ((int) $run->started_by_user_id !== (int) $token->user_id
            || (string) $run->device_id !== (string) $token->device_id) {
            return $this->error(
                'forbidden',
                'The authenticated plugin token does not own this run.',
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    public function assertReferences(bool $consistent, string $message): ?JsonResponse
    {
        return $consistent
            ? null
            : $this->error('schema_validation_failed', $message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function assertArtifactBelongsToTransfer(
        object $artifact,
        object $transfer,
        string $transferId,
        string $scope,
    ): ?JsonResponse {
        $expectedPath = "devboard/artifacts/{$scope}/{$transferId}/{$artifact->id}/artifact";
        $consistent = (string) $artifact->project_id === (string) $transfer->project_id
            && (string) $artifact->repository_id === (string) $transfer->repository_id
            && (string) $artifact->run_id === (string) $transfer->run_id
            && (string) $artifact->storage_path === $expectedPath;

        return $this->assertReferences($consistent, 'Artifact does not belong to the requested upload.');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
