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
        'evidence_packs' => 'hades_evidence_packs',
        'diagnosis_reports' => 'hades_diagnosis_reports',
    ];

    private const DELETE_ORDER = [
        'hades_evidence_packs',
        'hades_diagnosis_reports',
        'hades_source_slices',
        'hades_bug_evidence_items',
        'hades_bug_reports',
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

        $counts = DB::transaction(function () use ($binding, $validated, $dryRun): array {
            $counts = [];

            foreach (self::DELETE_ORDER as $table) {
                $query = DB::table($table)
                    ->where('project_id', $validated['project_id'])
                    ->where('workspace_binding_id', $binding->id);
                $counts[$table] = (clone $query)->count();

                if (! $dryRun && $counts[$table] > 0) {
                    $query->delete();
                }
            }

            return $counts;
        });

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
        $counts = DB::transaction(function () use ($binding, $validated, $dryRun, $cutoff): array {
            $counts = [];

            foreach (self::DELETE_ORDER as $table) {
                $query = DB::table($table)
                    ->where('project_id', $validated['project_id'])
                    ->where('workspace_binding_id', $binding->id)
                    ->where('created_at', '<', $cutoff);
                $counts[$table] = (clone $query)->count();

                if (! $dryRun && $counts[$table] > 0) {
                    $query->delete();
                }
            }

            return $counts;
        });

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

        foreach (['environment', 'affected_refs', 'payload', 'evidence_refs', 'freshness', 'graph_refs', 'source_slice_ids'] as $key) {
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

    private function contentFields(string $table): array
    {
        return match ($table) {
            'hades_bug_reports' => ['symptom', 'environment', 'affected_refs'],
            'hades_bug_evidence_items' => ['summary', 'payload'],
            'hades_source_slices' => ['content_redacted'],
            'hades_evidence_packs' => ['summary', 'payload', 'evidence_refs', 'graph_refs'],
            'hades_diagnosis_reports' => ['root_cause', 'mechanism', 'payload', 'evidence_refs'],
            default => [],
        };
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
