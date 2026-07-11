<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Models\PersephoneAgentMessage;
use App\Services\Hades\PersephoneAgentMessageConflict;
use App\Services\Hades\PersephoneAgentMessageStore;
use App\Services\Hades\PersephoneAgentMessageValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PersephoneController extends Controller
{
    public function __construct(
        private readonly PersephoneAgentMessageStore $store,
        private readonly PersephoneAgentMessageValidator $validator,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $envelope = $this->validator->envelope($request);
        $target = $this->targetAgent($agent, $envelope);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        if ($this->validator->requiresWorkspaceBinding($envelope['capability'])
            && $envelope['target_workspace_binding_id'] === null) {
            return $this->error(
                'workspace_binding_required',
                'A workspace binding is required for this capability.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $binding = $this->targetBinding($target, $envelope);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        try {
            $stored = $this->store->store($envelope);
        } catch (PersephoneAgentMessageConflict $exception) {
            return $this->error('message_conflict', $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return response()->json(
            ['event' => $stored['message']->eventEnvelope()],
            $stored['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    public function inbox(Request $request): JsonResponse
    {
        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $filters = $this->validator->inbox($request);

        $authorizationError = $this->authorizeInbox($agent, $filters);

        if ($authorizationError instanceof JsonResponse) {
            return $authorizationError;
        }

        $binding = null;

        if ($filters['target_workspace_binding_id'] !== null) {
            $binding = $this->targetBinding(
                $agent,
                ['target_workspace_binding_id' => $filters['target_workspace_binding_id']],
            );

            if ($binding instanceof JsonResponse) {
                return $binding;
            }
        }

        if ($filters['cursor'] !== null && ! PersephoneAgentMessage::query()
            ->forTarget($filters['project_id'], $filters['target_agent_id'])
            ->whereKey($filters['cursor'])
            ->exists()) {
            return $this->error('cursor_not_found', 'The cursor does not belong to this project and target.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $query = PersephoneAgentMessage::query()
            ->forTarget($filters['project_id'], $filters['target_agent_id'])
            ->notExpired()
            ->where(function ($query): void {
                $query->whereNull('target_workspace_binding_id')
                    ->orWhereHas('targetWorkspaceBinding', function ($binding): void {
                        $binding->where('status', 'linked')->whereNull('unlinked_at');
                    });
            });

        if ($binding !== null) {
            $query->where(function ($query) use ($binding): void {
                $query->whereNull('target_workspace_binding_id')
                    ->orWhere('target_workspace_binding_id', $binding->id);
            });
        }

        if ($filters['cursor'] !== null) {
            $query->where('id', '>', $filters['cursor']);
        }

        $messages = $query
            ->orderBy('id')
            ->limit($filters['limit'])
            ->get();

        return response()->json([
            'events' => $messages->map(
                fn (PersephoneAgentMessage $message): array => $message->eventEnvelope(),
            )->values()->all(),
            'cursor' => $messages->last()?->id ?? $filters['cursor'],
        ]);
    }

    public function events(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];

        if ($agent->project_id !== $validated['project_id']) {
            return response('event: error'."\n".'data: '.json_encode(['code' => 'project_mismatch'])."\n\n", Response::HTTP_FORBIDDEN, [
                'Content-Type' => 'text/event-stream; charset=UTF-8',
                'Cache-Control' => 'no-cache',
            ]);
        }

        $limit = (int) ($validated['limit'] ?? 50);
        $body = DB::table('hades_persephone_events')
            ->where('project_id', $validated['project_id'])
            ->where(function ($query) use ($agent): void {
                $query->whereNull('hades_agent_id')->orWhere('hades_agent_id', $agent->id);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(function (object $event): string {
                return 'event: '.$event->event_type."\n".'data: '.json_encode($this->payload($event), JSON_THROW_ON_ERROR)."\n";
            })
            ->implode("\n");

        return response($body."\n", Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    private function targetAgent(object $agent, array $envelope): mixed
    {
        if ($agent->project_id !== $envelope['project_id']) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        if ($agent->external_agent_id !== $envelope['sender_agent_id']) {
            return $this->error('sender_mismatch', 'The sender agent does not match the authenticated Hades agent.', Response::HTTP_FORBIDDEN);
        }

        $target = DB::table('hades_agents')
            ->where('project_id', $envelope['project_id'])
            ->where('external_agent_id', $envelope['target_agent_id'])
            ->first();

        if (! $target) {
            return $this->error(
                'target_agent_not_found',
                'The target agent was not found.',
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($target->status !== 'active') {
            return $this->error('target_agent_inactive', 'The target agent is not active.', Response::HTTP_FORBIDDEN);
        }

        return $target;
    }

    private function targetBinding(object $target, array $envelope): mixed
    {
        $bindingId = $envelope['target_workspace_binding_id'] ?? null;

        if ($bindingId === null) {
            return null;
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $bindingId)
            ->where('project_id', $target->project_id)
            ->where('hades_agent_id', $target->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        if ($binding->status !== 'linked' || $binding->unlinked_at !== null) {
            return $this->error('workspace_binding_inactive', 'Workspace binding is not active.', Response::HTTP_FORBIDDEN);
        }

        return $binding;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function authorizeInbox(object $agent, array $filters): ?JsonResponse
    {
        if ($agent->project_id !== $filters['project_id']) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        if ($agent->external_agent_id !== $filters['target_agent_id']) {
            return $this->error('target_agent_mismatch', 'The inbox target must match the authenticated Hades agent.', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function payload(object $event): array
    {
        return [
            'id' => $event->id,
            'project_id' => $event->project_id,
            'workspace_binding_id' => $event->workspace_binding_id,
            'event_type' => $event->event_type,
            'payload' => $this->decode($event->payload),
            'read_at' => $event->read_at,
            'created_at' => $event->created_at,
        ];
    }

    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }
}
