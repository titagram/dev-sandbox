<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Exceptions\ProjectLogbookException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Models\ProjectLogbookEntry;
use App\Projects\ProjectLifecycleService;
use App\Services\ProjectLogbookService;
use App\Support\ProjectLogbookActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class DashboardProjectLogbookController extends Controller
{
    use ChecksDashboardRoles;

    private const EVENT_TYPES = ['change', 'creation', 'import', 'projection', 'verification', 'wiki', 'decision', 'failure', 'rollback', 'note'];

    private const SEVERITIES = ['info', 'warning', 'error'];

    private const ACTOR_KINDS = ['user', 'agent', 'subagent', 'system'];

    public function __construct(private readonly ProjectLogbookService $logbook) {}

    public function index(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);
        $this->assertProjectExists($project);
        $validated = $request->validate([
            'types' => ['sometimes', 'array', 'list', 'max:10'], 'types.*' => ['string', Rule::in(self::EVENT_TYPES)],
            'actor' => ['sometimes', 'string', Rule::in(self::ACTOR_KINDS)], 'severity' => ['sometimes', 'string', Rule::in(self::SEVERITIES)],
            'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'], 'q' => ['sometimes', 'string', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:2048'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        try {
            $result = $this->logbook->listForProject($project, $this->filters($validated), $validated['cursor'] ?? null, (int) ($validated['limit'] ?? 20));
        } catch (ProjectLogbookException $exception) {
            return $this->logbookError($exception);
        }

        return response()->json(['project_id' => $project, 'items' => array_map($this->entryPayload(...), $result['items']), 'next_cursor' => $result['next_cursor']]);
    }

    public function show(Request $request, string $project, string $entry): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);
        $this->assertProjectExists($project);
        $row = $this->logbook->showForProject($project, $entry);
        abort_unless($row, Response::HTTP_NOT_FOUND);

        return response()->json(['project_id' => $project, 'entry' => $this->entryPayload($row)]);
    }

    public function storeNote(Request $request, ProjectLifecycleService $lifecycle, string $project): JsonResponse
    {
        $this->abortUnlessDashboardMutator($request);
        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }
        $validated = $request->validate([
            'event_type' => ['required', 'string', Rule::in(['note', 'decision'])], 'severity' => ['required', 'string', Rule::in(self::SEVERITIES)],
            'summary' => ['required', 'string', 'max:240'], 'narrative_markdown' => ['present', 'nullable', 'string', 'max:8000'],
            'references' => ['present', 'array', 'list', 'max:80'], 'correlation_id' => ['present', 'nullable', 'string', 'max:191'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'], 'supersedes_entry_id' => ['present', 'nullable', 'string', 'max:191'],
            'actor' => ['prohibited'], 'payload' => ['prohibited'], 'project_id' => ['prohibited'], 'occurred_at' => ['prohibited'], 'recorded_at' => ['prohibited'],
        ]);
        $user = $request->user();
        $roles = $this->dashboardRoles($user);
        try {
            $result = $this->logbook->append([
                'project_id' => $project, 'event_type' => $validated['event_type'], 'severity' => $validated['severity'], 'summary' => $validated['summary'],
                'narrative_markdown' => $validated['narrative_markdown'], 'references' => $validated['references'], 'correlation_id' => $validated['correlation_id'],
                'idempotency_key' => $validated['idempotency_key'], 'payload' => ['source' => 'dashboard', 'dashboard_user_id' => $user->id], 'supersedes_entry_id' => $validated['supersedes_entry_id'],
            ], new ProjectLogbookActor('user', (string) $user->name, userId: (int) $user->id, role: $roles[0] ?? null));
        } catch (ProjectLogbookException $exception) {
            return $this->logbookError($exception);
        }

        return response()->json(['entry' => $this->entryPayload($result['entry']), 'replayed' => $result['replayed']], $result['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    private function abortUnlessDashboardMutator(Request $request): void
    {
        abort_unless($this->userHasRole($request->user(), 'PM') || $this->userHasRole($request->user(), 'Developer') || $this->userHasRole($request->user(), 'Admin'), Response::HTTP_FORBIDDEN);
    }

    private function assertProjectExists(string $project): void
    {
        abort_unless(DB::table('projects')->where('id', $project)->exists(), Response::HTTP_NOT_FOUND);
    }

    /** @param array<string,mixed> $validated @return array<string,mixed> */
    private function filters(array $validated): array
    {
        return array_filter(['event_types' => $validated['types'] ?? null, 'actor_kind' => $validated['actor'] ?? null, 'severity' => $validated['severity'] ?? null, 'from' => $validated['from'] ?? null, 'to' => $validated['to'] ?? null, 'q' => $validated['q'] ?? null], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string,mixed> */
    private function entryPayload(ProjectLogbookEntry $entry): array
    {
        return ['id' => $entry->id, 'project_id' => $entry->project_id, 'occurred_at' => $entry->occurred_at?->toISOString(), 'recorded_at' => $entry->recorded_at?->toISOString(), 'actor' => ['kind' => $entry->actor_kind, 'label' => $entry->actor_label, 'user_id' => $entry->actor_user_id, 'agent_id' => $entry->actor_agent_id, 'device_id' => $entry->actor_device_id, 'role' => $entry->actor_role, 'model' => $entry->actor_model], 'event_type' => $entry->event_type, 'severity' => $entry->severity, 'summary' => $entry->summary, 'narrative_markdown' => $entry->narrative_markdown, 'references' => $entry->references, 'correlation_id' => $entry->correlation_id, 'payload' => $entry->payload, 'supersedes_entry_id' => $entry->supersedes_entry_id];
    }

    private function logbookError(ProjectLogbookException $exception): JsonResponse
    {
        return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
    }
}
