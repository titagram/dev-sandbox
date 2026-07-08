<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DoctorReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:ok,warning,error,failed'],
            'payload' => ['required', 'array'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = null;

        if ($agent->project_id !== $validated['project_id']) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        if (($validated['workspace_binding_id'] ?? null) !== null) {
            $binding = DB::table('hades_workspace_bindings')
                ->where('id', $validated['workspace_binding_id'])
                ->where('project_id', $validated['project_id'])
                ->where('hades_agent_id', $agent->id)
                ->first();

            if (! $binding) {
                return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
            }
        }

        $id = (string) Str::ulid();
        $now = now();

        DB::table('hades_doctor_reports')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding?->id,
            'status' => $validated['status'],
            'payload' => json_encode($validated['payload'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $report = DB::table('hades_doctor_reports')->where('id', $id)->first();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'report' => [
                'id' => $report->id,
                'project_id' => $report->project_id,
                'workspace_binding_id' => $report->workspace_binding_id,
                'status' => $report->status,
                'created_at' => $report->created_at,
            ],
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
