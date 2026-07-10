<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DataPrivacyController extends Controller
{
    private const EXPORT_TABLES = [
        'bug_reports' => 'hades_bug_reports',
        'bug_evidence' => 'hades_bug_evidence_items',
        'source_slices' => 'hades_source_slices',
        'source_slice_candidates' => 'hades_source_slice_candidates',
        'evidence_packs' => 'hades_evidence_packs',
        'causal_packs' => 'hades_causal_packs',
        'diagnosis_reports' => 'hades_diagnosis_reports',
        'artifacts' => 'hades_agent_artifacts',
        'search_documents' => 'hades_search_documents',
        'memory_proposals' => 'hades_memory_proposals',
        'agent_jobs' => 'hades_agent_jobs',
        'doctor_reports' => 'hades_doctor_reports',
        'persephone_events' => 'hades_persephone_events',
    ];

    private const DELETE_ORDER = [
        'hades_search_documents',
        'hades_source_slice_candidates',
        'hades_causal_packs',
        'hades_evidence_packs',
        'hades_diagnosis_reports',
        'hades_source_slices',
        'hades_bug_evidence_items',
        'hades_bug_reports',
        'hades_agent_artifacts',
        'hades_memory_proposals',
        'hades_doctor_reports',
        'hades_persephone_events',
        'hades_agent_jobs',
    ];

    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'include_content' => ['nullable', 'boolean'],
        ]);

        $binding = $this->linkedBinding($request, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $includeContent = (bool) ($validated['include_content'] ?? true);
        $collections = [];
        $counts = [];

        foreach (self::EXPORT_TABLES as $key => $table) {
            $rows = DB::table($table)
                ->where('project_id', $validated['project_id'])
                ->where('workspace_binding_id', $binding->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => $this->rowPayload($table, $row, $includeContent))
                ->values()
                ->all();

            $collections[$key] = $rows;
            $counts[$key] = count($rows);
        }

        $jobIds = array_column($collections['agent_jobs'], 'id');
        $jobEvents = $this->jobEventsQuery($jobIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->rowPayload('hades_agent_job_events', $row, $includeContent))
            ->values()
            ->all();
        $collections['agent_job_events'] = $jobEvents;
        $counts['agent_job_events'] = count($jobEvents);

        $resolvedBugMemory = $this->resolvedBugMemoryQuery($validated['project_id'], $binding->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->rowPayload('project_memory_entries', $row, $includeContent))
            ->values()
            ->all();
        $collections['resolved_bug_memory'] = $resolvedBugMemory;
        $counts['resolved_bug_memory'] = count($resolvedBugMemory);

        $proposalMemoryIds = DB::table('hades_memory_proposals')
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->whereNotNull('memory_entry_id')
            ->pluck('memory_entry_id')
            ->all();
        $proposalMemory = DB::table('project_memory_entries')
            ->where('project_id', $validated['project_id'])
            ->where('source', 'hades_agent')
            ->whereIn('id', $proposalMemoryIds ?: [''])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->rowPayload('project_memory_entries', $row, $includeContent))
            ->values()
            ->all();
        $collections['proposal_memory'] = $proposalMemory;
        $counts['proposal_memory'] = count($proposalMemory);

        $this->auditPrivacyAction($request, 'hades.privacy_exported', $binding->id, [
            'scope' => 'workspace_binding',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'include_content' => $includeContent,
            'counts' => $counts,
        ]);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'scope' => 'workspace_binding',
            'include_content' => $includeContent,
            'counts' => $counts,
            'collections' => $collections,
            'server_time' => now()->toISOString(),
        ]);
    }

    public function delete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'confirm' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $binding = $this->linkedBinding($request, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $dryRun = (bool) ($validated['dry_run'] ?? true);
        $confirm = (bool) ($validated['confirm'] ?? false);

        if (! $dryRun && ! $confirm) {
            return $this->error('delete_confirmation_required', 'Hades evidence delete requires confirm=true when dry_run=false.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $counts = DB::transaction(fn (): array => $this->deleteScopedData(
            $validated['project_id'],
            $binding->id,
            $dryRun,
        ));

        $this->auditPrivacyAction($request, 'hades.privacy_deleted', $binding->id, [
            'scope' => 'workspace_binding',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'dry_run' => $dryRun,
            'counts' => $counts,
        ]);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'scope' => 'workspace_binding',
            'dry_run' => $dryRun,
            $dryRun ? 'would_delete' : 'deleted' => $counts,
            'server_time' => now()->toISOString(),
        ]);
    }

    public function retentionCleanup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'confirm' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $binding = $this->linkedBinding($request, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $dryRun = (bool) ($validated['dry_run'] ?? true);
        $confirm = (bool) ($validated['confirm'] ?? false);

        if (! $dryRun && ! $confirm) {
            return $this->error('retention_cleanup_confirmation_required', 'Hades retention cleanup requires confirm=true when dry_run=false.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cutoff = now()->subDays((int) $validated['retention_days']);
        $counts = DB::transaction(fn (): array => $this->deleteScopedData(
            $validated['project_id'],
            $binding->id,
            $dryRun,
            $cutoff,
        ));

        $this->auditPrivacyAction($request, 'hades.retention_cleaned', $binding->id, [
            'scope' => 'workspace_binding',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'retention_days' => (int) $validated['retention_days'],
            'cutoff' => $cutoff->toISOString(),
            'dry_run' => $dryRun,
            'counts' => $counts,
        ]);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'scope' => 'workspace_binding',
            'retention_days' => (int) $validated['retention_days'],
            'cutoff' => $cutoff->toISOString(),
            'dry_run' => $dryRun,
            $dryRun ? 'would_delete' : 'deleted' => $counts,
            'server_time' => now()->toISOString(),
        ]);
    }

    private function linkedBinding(Request $request, string $projectId, string $bindingId): mixed
    {
        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];

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

    private function rowPayload(string $table, object $row, bool $includeContent): array
    {
        $payload = (array) $row;

        foreach (['environment', 'affected_refs', 'payload', 'result', 'evidence_refs', 'freshness', 'awareness', 'graph_refs', 'source_slice_ids', 'source_slice_refs', 'replay', 'blockers', 'metadata', 'artifact', 'provenance', 'declared_capabilities', 'effective_capabilities'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->decode($payload[$key]);
            }
        }

        foreach (['created_at', 'updated_at', 'occurred_at', 'observed_at'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->toIsoString($payload[$key]);
            }
        }

        if (! $includeContent) {
            foreach ($this->contentFields($table) as $key) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function auditPrivacyAction(Request $request, string $action, string $bindingId, array $payload): void
    {
        $auth = $request->attributes->get('hades_auth') ?? [];
        $agent = is_array($auth) ? ($auth['agent'] ?? null) : null;

        app(\App\Services\AuditLogger::class)->record($action, 'hades_workspace_binding', $bindingId, [
            'hades_agent_id' => $agent?->id,
        ] + $payload, [
            'type' => 'hades_agent',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function contentFields(string $table): array
    {
        return match ($table) {
            'hades_bug_reports' => ['symptom', 'environment', 'affected_refs'],
            'hades_bug_evidence_items' => ['summary', 'payload'],
            'hades_source_slices' => ['content_redacted'],
            'hades_evidence_packs' => ['summary', 'payload', 'evidence_refs', 'graph_refs'],
            'hades_causal_packs' => ['affected_refs', 'freshness', 'awareness', 'evidence_refs', 'graph_refs', 'source_slice_refs', 'replay', 'blockers'],
            'hades_diagnosis_reports' => ['root_cause', 'mechanism', 'payload', 'evidence_refs'],
            'hades_agent_artifacts' => ['artifact'],
            'hades_search_documents' => ['title', 'body', 'metadata'],
            'hades_memory_proposals' => ['summary', 'provenance', 'reason_message'],
            'hades_agent_jobs' => ['payload', 'result', 'error_message'],
            'hades_agent_job_events' => ['payload'],
            'hades_doctor_reports', 'hades_persephone_events' => ['payload'],
            'project_memory_entries' => ['summary', 'payload'],
            default => [],
        };
    }

    private function deleteScopedData(string $projectId, string $bindingId, bool $dryRun, ?Carbon $cutoff = null): array
    {
        $counts = [];
        $jobs = $this->scopedQuery('hades_agent_jobs', $projectId, $bindingId, $cutoff);
        $jobIds = (clone $jobs)->pluck('id')->all();
        $jobEvents = $this->jobEventsQuery($jobIds);
        $counts['hades_agent_job_events'] = (clone $jobEvents)->count();
        if (! $dryRun && $counts['hades_agent_job_events'] > 0) {
            $jobEvents->delete();
        }

        $resolvedMemoryIds = $this->resolvedBugMemoryQuery($projectId, $bindingId, $cutoff)->pluck('id')->all();
        $scopedProposals = $this->scopedQuery('hades_memory_proposals', $projectId, $bindingId, $cutoff);
        $scopedProposalIds = (clone $scopedProposals)->pluck('id')->all();
        $candidateProposalMemoryIds = (clone $scopedProposals)
            ->whereNotNull('memory_entry_id')
            ->pluck('memory_entry_id')
            ->all();
        $protectedProposalMemoryIds = $candidateProposalMemoryIds === []
            ? []
            : DB::table('hades_memory_proposals')
                ->whereIn('memory_entry_id', $candidateProposalMemoryIds)
                ->whereNotIn('id', $scopedProposalIds ?: [''])
                ->pluck('memory_entry_id')
                ->all();
        $proposalMemoryIds = array_values(array_diff($candidateProposalMemoryIds, $protectedProposalMemoryIds));
        $memoryIds = array_values(array_unique([...$resolvedMemoryIds, ...$proposalMemoryIds]));
        $memory = DB::table('project_memory_entries')
            ->where('project_id', $projectId)
            ->whereIn('id', $memoryIds ?: [''])
            ->where(function ($query) use ($proposalMemoryIds): void {
                $query->where('source', 'hades_diagnosis_report');
                if ($proposalMemoryIds !== []) {
                    $query->orWhere(function ($proposalQuery) use ($proposalMemoryIds): void {
                        $proposalQuery->where('source', 'hades_agent')->whereIn('id', $proposalMemoryIds);
                    });
                }
            })
            ->when($cutoff !== null, fn ($query) => $query->where('created_at', '<', $cutoff));
        $memoryIds = (clone $memory)->pluck('id')->all();
        $memoryLinks = DB::table('project_memory_links')->whereIn('memory_entry_id', $memoryIds ?: ['']);
        $memorySearch = DB::table('hades_search_documents')
            ->where('source_table', 'project_memory_entries')
            ->whereIn('source_id', $memoryIds ?: ['']);
        $counts['project_memory_links'] = (clone $memoryLinks)->count();
        $counts['project_memory_entries'] = count($memoryIds);
        $counts['project_memory_entries_from_proposals'] = count(array_intersect($memoryIds, $proposalMemoryIds));
        $counts['hades_search_documents_related_memory'] = (clone $memorySearch)->count();
        if (! $dryRun) {
            $memoryLinks->delete();
            $memorySearch->delete();
            $memory->delete();
        }

        foreach (self::DELETE_ORDER as $table) {
            $query = $this->scopedQuery($table, $projectId, $bindingId, $cutoff);
            $counts[$table] = (clone $query)->count();
            if (! $dryRun && $counts[$table] > 0) {
                $query->delete();
            }
        }

        return $counts;
    }

    private function scopedQuery(string $table, string $projectId, string $bindingId, ?Carbon $cutoff = null): mixed
    {
        return DB::table($table)
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $bindingId)
            ->when($cutoff !== null, fn ($query) => $query->where('created_at', '<', $cutoff));
    }

    /** @param list<string> $jobIds */
    private function jobEventsQuery(array $jobIds, ?Carbon $cutoff = null): mixed
    {
        return DB::table('hades_agent_job_events')
            ->whereIn('job_id', $jobIds ?: [''])
            ->when($cutoff !== null, fn ($query) => $query->where('created_at', '<', $cutoff));
    }

    private function resolvedBugMemoryQuery(string $projectId, string $bindingId, ?Carbon $cutoff = null): mixed
    {
        return DB::table('project_memory_entries')
            ->where('project_id', $projectId)
            ->where('source', 'hades_diagnosis_report')
            ->where('payload->workspace_binding_id', $bindingId)
            ->when($cutoff !== null, fn ($query) => $query->where('created_at', '<', $cutoff));
    }

    private function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function toIsoString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toISOString();
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
