<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\MemoryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class DashboardMemoryImportController extends Controller
{
    use ChecksDashboardRoles;

    public function index(Request $request, MemoryImportService $imports, string $project): JsonResponse
    {
        $this->abortUnlessReader($request);
        abort_unless(DB::table('projects')->where('id', $project)->where('status', '!=', 'deleted')->exists(), 404);

        $batches = DB::table('memory_import_batches')
            ->where('project_id', $project)
            ->orderByDesc('created_at')
            ->limit(50)
            ->pluck('id')
            ->map(fn (string $batchId): array => $imports->batchPayload($batchId))
            ->all();

        return response()->json(['import_batches' => $batches]);
    }

    public function store(Request $request, MemoryImportService $imports, string $project): JsonResponse
    {
        $this->abortUnlessMutator($request);
        abort_unless(DB::table('projects')->where('id', $project)->where('status', 'active')->exists(), 404);

        $validated = $request->validate([
            'source_workspace_binding_id' => [
                'nullable',
                'string',
                Rule::exists('hades_workspace_bindings', 'id')
                    ->where(fn ($query) => $query->where('project_id', $project)->where('status', 'linked')),
            ],
            'target_workspace_binding_id' => [
                'required',
                'string',
                Rule::exists('hades_workspace_bindings', 'id')
                    ->where(fn ($query) => $query->where('project_id', $project)->where('status', 'linked')),
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'array'],
            'mode' => ['nullable', 'string', Rule::in(['copy_as_proposals'])],
            'filters' => ['nullable', 'array'],
            'filters.kinds' => ['nullable', 'array'],
            'filters.kinds.*' => ['string', Rule::in(['decision', 'implementation', 'clarification', 'risk', 'verification', 'handoff', 'incident', 'agent_note'])],
            'filters.since' => ['nullable', 'string', 'max:64'],
            'filters.limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'dedupe_strategy' => ['nullable', 'string', Rule::in(['source_hash', 'summary_payload_hash', 'provenance_hash'])],
            'conflict_policy' => ['nullable', 'string', Rule::in(['skip', 'proposal', 'mark_conflicted'])],
            'entries' => ['nullable', 'array', 'min:1', 'max:100'],
            'entries.*.source_local_id' => ['nullable', 'string', 'max:191'],
            'entries.*.source_hash' => ['required', 'string', 'max:191'],
            'entries.*.kind' => ['nullable', 'string', Rule::in(['decision', 'implementation', 'clarification', 'risk', 'verification', 'handoff', 'incident', 'agent_note'])],
            'entries.*.summary' => ['required', 'string', 'min:8', 'max:4000'],
            'entries.*.payload' => ['nullable', 'array'],
            'entries.*.provenance' => ['nullable', 'array'],
        ]);

        $entries = $validated['entries'] ?? $imports->entriesFromProjectMemory(
            $project,
            $validated['source_workspace_binding_id'] ?? null,
            $validated['target_workspace_binding_id'],
            $validated['filters'] ?? [],
        );

        if ($entries === []) {
            throw ValidationException::withMessages([
                'entries' => 'No project memory entries matched the import filters.',
            ]);
        }

        $batch = $imports->createBatch(
            $project,
            $validated['source_workspace_binding_id'] ?? null,
            $validated['target_workspace_binding_id'],
            $request->user()->id,
            null,
            $entries,
            $validated['reason'] ?? null,
            array_merge($validated['source'] ?? [], [
                'mode' => $validated['mode'] ?? 'copy_as_proposals',
                'filters' => $validated['filters'] ?? [],
                'dedupe_strategy' => $validated['dedupe_strategy'] ?? 'source_hash',
                'conflict_policy' => $validated['conflict_policy'] ?? 'skip',
            ]),
        );

        return response()->json(['import_batch' => $batch], Response::HTTP_CREATED);
    }

    public function show(Request $request, MemoryImportService $imports, string $project, string $batch): JsonResponse
    {
        $this->abortUnlessReader($request);
        abort_unless(DB::table('memory_import_batches')->where('id', $batch)->where('project_id', $project)->exists(), 404);

        return response()->json(['import_batch' => $imports->batchPayload($batch)]);
    }

    private function abortUnlessReader(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }

    private function abortUnlessMutator(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }
}
