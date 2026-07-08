<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BugReportController extends Controller
{
    private const SEVERITIES = ['unknown', 'low', 'medium', 'high', 'critical'];

    private const STATUSES = ['open', 'investigating', 'resolved', 'closed'];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:191'],
            'symptom' => ['required', 'string', 'max:8000'],
            'severity' => ['nullable', 'string', Rule::in(self::SEVERITIES)],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'environment' => ['nullable', 'array'],
            'affected_refs' => ['nullable', 'array'],
            'observed_at' => ['nullable', 'date'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $now = now();
        $id = (string) Str::ulid();
        DB::table('hades_bug_reports')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'title' => $validated['title'],
            'symptom' => $validated['symptom'],
            'severity' => $validated['severity'] ?? 'unknown',
            'status' => $validated['status'] ?? 'open',
            'environment' => isset($validated['environment']) ? json_encode($validated['environment'], JSON_THROW_ON_ERROR) : null,
            'affected_refs' => isset($validated['affected_refs']) ? json_encode($validated['affected_refs'], JSON_THROW_ON_ERROR) : null,
            'observed_at' => isset($validated['observed_at']) ? Carbon::parse($validated['observed_at']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $report = DB::table('hades_bug_reports')->where('id', $id)->first();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'bug_report' => $this->reportPayload($report),
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, string $bugReport): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $report = DB::table('hades_bug_reports')
            ->where('id', $bugReport)
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->first();

        if (! $report) {
            return $this->error('bug_report_not_found', 'Hades bug report was not found.', Response::HTTP_NOT_FOUND);
        }

        $evidence = DB::table('hades_bug_evidence_items')
            ->where('bug_report_id', $report->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $item): array => BugEvidenceController::evidencePayload($item))
            ->values()
            ->all();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'bug_report' => $this->reportPayload($report),
            'evidence' => $evidence,
            'server_time' => now()->toISOString(),
        ]);
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $bindingId)
            ->where('project_id', $projectId)
            ->where('hades_agent_id', $agent->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        if ($binding->status !== 'linked') {
            return $this->error('workspace_binding_unlinked', 'Workspace binding is not linked.', Response::HTTP_CONFLICT);
        }

        return $binding;
    }

    private function reportPayload(object $report): array
    {
        return [
            'id' => $report->id,
            'project_id' => $report->project_id,
            'workspace_binding_id' => $report->workspace_binding_id,
            'title' => $report->title,
            'symptom' => $report->symptom,
            'severity' => $report->severity,
            'status' => $report->status,
            'environment' => $this->decode($report->environment),
            'affected_refs' => $this->decode($report->affected_refs),
            'observed_at' => $this->toIsoString($report->observed_at),
            'created_at' => $this->toIsoString($report->created_at),
            'updated_at' => $this->toIsoString($report->updated_at),
        ];
    }

    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    private function toIsoString(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
