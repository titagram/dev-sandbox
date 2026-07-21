<?php

namespace App\Http\Controllers\Hades;

use App\Exceptions\ProjectLogbookException;
use App\Http\Controllers\Controller;
use App\Models\ProjectLogbookEntry;
use App\Services\AuditLogger;
use App\Services\ProjectLogbookService;
use App\Support\ProjectLogbookActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

final class ProjectLogbookController extends Controller
{
    private const EVENT_TYPES = ['change', 'creation', 'import', 'projection', 'verification', 'wiki', 'decision', 'failure', 'rollback', 'note'];

    private const SEVERITIES = ['info', 'warning', 'error'];

    private const ACTOR_KINDS = ['user', 'agent', 'subagent', 'system'];

    public function __construct(private readonly ProjectLogbookService $logbook) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validatedScopeAndFilters($request);
        $binding = $this->linkedBinding($request->attributes->get('hades_auth')['agent'], $validated['project_id'], $validated['workspace_binding_id']);
        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        try {
            $result = $this->logbook->listForProject($validated['project_id'], $this->filters($validated), $validated['cursor'] ?? null, (int) ($validated['limit'] ?? 20));
        } catch (ProjectLogbookException $exception) {
            return $this->logbookError($exception);
        }

        return response()->json([
            'protocol_version' => 'v1', 'project_id' => $validated['project_id'], 'workspace_binding_id' => $binding->id,
            'items' => array_map($this->entryPayload(...), $result['items']), 'next_cursor' => $result['next_cursor'],
        ]);
    }

    public function show(Request $request, string $entry): JsonResponse
    {
        $validated = $this->validatedScope($request);
        $binding = $this->linkedBinding($request->attributes->get('hades_auth')['agent'], $validated['project_id'], $validated['workspace_binding_id']);
        if ($binding instanceof JsonResponse) {
            return $binding;
        }
        $row = $this->logbook->showForProject($validated['project_id'], $entry);
        if ($row === null) {
            return $this->error('logbook_entry_not_found', 'Project logbook entry was not found.', Response::HTTP_NOT_FOUND);
        }

        return response()->json(['protocol_version' => 'v1', 'project_id' => $validated['project_id'], 'workspace_binding_id' => $binding->id, 'entry' => $this->entryPayload($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $payloadIsJsonObject = $this->payloadIsJsonObject($request);
        $validated = $request->validate([
            ...$this->scopeRules(),
            'event_type' => ['required', 'string', Rule::in(self::EVENT_TYPES)],
            'severity' => ['required', 'string', Rule::in(self::SEVERITIES)],
            'summary' => ['required', 'string', 'max:240'],
            'narrative_markdown' => ['present', 'nullable', 'string', 'max:8000'],
            'references' => ['present', 'array', 'list', 'max:80'],
            'correlation_id' => ['present', 'nullable', 'string', 'max:191'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
            'payload' => [
                'present',
                'array',
                static function (string $attribute, mixed $value, \Closure $fail) use ($payloadIsJsonObject): void {
                    if (! $payloadIsJsonObject) {
                        $fail('The payload field must be a JSON object.');
                    }
                },
            ],
            'supersedes_entry_id' => ['present', 'nullable', 'string', 'max:191'],
            'actor' => ['prohibited'], 'occurred_at' => ['prohibited'], 'recorded_at' => ['prohibited'],
        ]);
        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);
        if ($binding instanceof JsonResponse) {
            return $binding;
        }
        if (! $this->hasWriteCapability($agent)) {
            app(AuditLogger::class)->record(
                'permission.denied',
                'authorization',
                'write_project_logbook',
                [
                    'ability' => 'write_project_logbook',
                    'project_id' => $validated['project_id'],
                    'workspace_binding_id' => $binding->id,
                    'hades_agent_id' => $agent->id,
                ],
                [
                    'type' => 'hades_agent',
                    'device_id' => $auth['token']->device_id ?? null,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );

            return response()->json(['code' => 'logbook_capability_not_allowed', 'message' => 'The write_project_logbook capability is not enabled for this Hades agent.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $this->logbook->append([
                'project_id' => $validated['project_id'], 'event_type' => $validated['event_type'], 'severity' => $validated['severity'],
                'summary' => $validated['summary'], 'narrative_markdown' => $validated['narrative_markdown'], 'references' => $validated['references'],
                'correlation_id' => $validated['correlation_id'], 'idempotency_key' => $validated['idempotency_key'],
                'payload' => $validated['payload'], 'supersedes_entry_id' => $validated['supersedes_entry_id'],
            ], new ProjectLogbookActor('agent', (string) $agent->label, agentId: (string) $agent->id, deviceId: $auth['token']->device_id ?? null));
        } catch (ProjectLogbookException $exception) {
            return $this->logbookError($exception);
        }

        return response()->json(['entry' => $this->entryPayload($result['entry']), 'replayed' => $result['replayed']], $result['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private function validatedScopeAndFilters(Request $request): array
    {
        return $request->validate([
            ...$this->scopeRules(),
            'types' => ['sometimes', 'array', 'list', 'max:10'], 'types.*' => ['string', Rule::in(self::EVENT_TYPES)],
            'actor' => ['sometimes', 'string', Rule::in(self::ACTOR_KINDS)], 'severity' => ['sometimes', 'string', Rule::in(self::SEVERITIES)],
            'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'], 'q' => ['sometimes', 'string', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:2048'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedScope(Request $request): array
    {
        return $request->validate($this->scopeRules());
    }

    /** @return array<string, array<int, mixed>> */
    private function scopeRules(): array
    {
        return ['project_id' => ['required', 'string', 'max:191'], 'workspace_binding_id' => ['required', 'string', 'max:191']];
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function filters(array $validated): array
    {
        return array_filter([
            'event_types' => $validated['types'] ?? null, 'actor_kind' => $validated['actor'] ?? null, 'severity' => $validated['severity'] ?? null,
            'from' => $validated['from'] ?? null, 'to' => $validated['to'] ?? null, 'q' => $validated['q'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }
        $binding = DB::table('hades_workspace_bindings')->where('id', $bindingId)->where('project_id', $projectId)->where('hades_agent_id', $agent->id)->first();
        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }
        if ($binding->status !== 'linked') {
            return $this->error('workspace_binding_unlinked', 'Workspace binding is not linked.', Response::HTTP_CONFLICT);
        }

        return $binding;
    }

    private function hasWriteCapability(object $agent): bool
    {
        $decoded = is_string($agent->effective_capabilities ?? null) ? json_decode($agent->effective_capabilities, true) : ($agent->effective_capabilities ?? []);

        return is_array($decoded) && in_array('write_project_logbook', $decoded, true);
    }

    private function payloadIsJsonObject(Request $request): bool
    {
        try {
            $body = json_decode($request->getContent(), false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return $body instanceof stdClass
            && property_exists($body, 'payload')
            && $body->payload instanceof stdClass;
    }

    /** @return array<string, mixed> */
    private function entryPayload(ProjectLogbookEntry $entry): array
    {
        return ['id' => $entry->id, 'project_id' => $entry->project_id, 'occurred_at' => $entry->occurred_at?->toISOString(), 'recorded_at' => $entry->recorded_at?->toISOString(),
            'actor' => ['kind' => $entry->actor_kind, 'label' => $entry->actor_label, 'user_id' => $entry->actor_user_id, 'agent_id' => $entry->actor_agent_id, 'device_id' => $entry->actor_device_id, 'role' => $entry->actor_role, 'model' => $entry->actor_model],
            'event_type' => $entry->event_type, 'severity' => $entry->severity, 'summary' => $entry->summary, 'narrative_markdown' => $entry->narrative_markdown,
            'references' => $entry->references, 'correlation_id' => $entry->correlation_id, 'payload' => $entry->payload === [] ? (object) [] : $entry->payload, 'supersedes_entry_id' => $entry->supersedes_entry_id];
    }

    private function logbookError(ProjectLogbookException $exception): JsonResponse
    {
        return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
