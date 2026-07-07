<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesEvidencePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DiagnosisReportController extends Controller
{
    public function __construct(private readonly HadesEvidencePolicy $policy) {}

    private const CONFIDENCE = ['high', 'medium', 'low', 'insufficient'];

    private const STATUSES = ['draft', 'final'];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'bug_report_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'confidence' => ['required', 'string', Rule::in(self::CONFIDENCE)],
            'root_cause' => ['required', 'string', 'max:4000'],
            'mechanism' => ['nullable', 'string', 'max:8000'],
            'evidence_refs' => ['nullable', 'array'],
            'freshness' => ['nullable', 'array'],
            'payload' => ['nullable', 'array'],
            'redactions' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if ($policyError = $this->policy->validateDiagnosisReport(
            $validated['root_cause'],
            $validated['mechanism'] ?? null,
            $validated['evidence_refs'] ?? [],
            $validated['freshness'] ?? [],
            $validated['payload'] ?? [],
        )) {
            return $this->error($policyError['code'], $policyError['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        if (($validated['bug_report_id'] ?? null) !== null) {
            $exists = DB::table('hades_bug_reports')
                ->where('id', $validated['bug_report_id'])
                ->where('project_id', $validated['project_id'])
                ->where('workspace_binding_id', $binding->id)
                ->exists();

            if (! $exists) {
                return $this->error('bug_report_not_found', 'Hades bug report was not found.', Response::HTTP_NOT_FOUND);
            }
        }

        $id = (string) Str::ulid();
        $now = now();

        DB::table('hades_diagnosis_reports')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'bug_report_id' => $validated['bug_report_id'] ?? null,
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'status' => $validated['status'] ?? 'draft',
            'confidence' => $validated['confidence'],
            'root_cause' => $validated['root_cause'],
            'mechanism' => $validated['mechanism'] ?? null,
            'evidence_refs' => isset($validated['evidence_refs']) ? json_encode($validated['evidence_refs'], JSON_THROW_ON_ERROR) : null,
            'freshness' => isset($validated['freshness']) ? json_encode($validated['freshness'], JSON_THROW_ON_ERROR) : null,
            'payload' => isset($validated['payload']) ? json_encode($validated['payload'], JSON_THROW_ON_ERROR) : null,
            'redactions' => (int) ($validated['redactions'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $report = DB::table('hades_diagnosis_reports')->where('id', $id)->first();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'diagnosis_report' => self::reportPayload($report),
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    public static function reportPayload(object $report): array
    {
        return [
            'id' => $report->id,
            'project_id' => $report->project_id,
            'workspace_binding_id' => $report->workspace_binding_id,
            'bug_report_id' => $report->bug_report_id,
            'status' => $report->status,
            'confidence' => $report->confidence,
            'root_cause' => $report->root_cause,
            'mechanism' => $report->mechanism,
            'evidence_refs' => self::decode($report->evidence_refs),
            'freshness' => self::decode($report->freshness),
            'payload' => self::decode($report->payload),
            'redactions' => (int) $report->redactions,
            'created_at' => self::toIsoString($report->created_at),
            'updated_at' => self::toIsoString($report->updated_at),
            'version' => 'diagnosis_report_'.hash('sha256', $report->id.'|'.$report->updated_at),
        ];
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

    private static function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    private static function toIsoString(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
