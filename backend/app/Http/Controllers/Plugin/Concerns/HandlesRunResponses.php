<?php

namespace App\Http\Controllers\Plugin\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

trait HandlesRunResponses
{
    protected function runOrFail(string $run): object
    {
        $row = DB::table('runs')->where('id', $run)->first();

        abort_unless($row, 404);

        return $row;
    }

    protected function assertRunActive(object $run): ?JsonResponse
    {
        if (in_array($run->status, ['finished', 'failed', 'aborted'], true)) {
            return $this->runError(
                'run_not_active',
                'Run is already terminal and cannot accept new events.',
                Response::HTTP_CONFLICT,
            );
        }

        return null;
    }

    protected function appendRunEvent(
        string $runId,
        string $eventType,
        string $severity,
        string $message,
        array $payload = [],
    ): void {
        DB::table('run_events')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'run_id' => $runId,
            'event_type' => $eventType,
            'severity' => $severity,
            'message' => $message,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    protected function runError(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
