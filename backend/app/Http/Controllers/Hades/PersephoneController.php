<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\PersephoneAgentMessageConflict;
use App\Services\Hades\PersephoneAgentMessageDelivery;
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
        private readonly PersephoneAgentMessageDelivery $delivery,
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

        $page = $this->delivery->page($request, $agent);

        if ($page instanceof JsonResponse) {
            return $page;
        }

        $messages = $page['messages'];

        return response()->json([
            'events' => $messages->map(
                fn ($message): array => $message->eventEnvelope(),
            )->values()->all(),
            'cursor' => $page['cursor'],
        ]);
    }

    public function events(Request $request): Response
    {
        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];

        $page = $this->delivery->page($request, $agent);

        if ($page instanceof JsonResponse) {
            return $page;
        }

        $blocks = $page['messages']->map(function ($message): string {
            $event = $message->eventEnvelope();
            $json = json_encode(
                $event,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            return 'id: '.$event['id']."\n".'event: message'."\n".'data: '.$json;
        })->all();

        $stop = json_encode([
            'reason' => 'bounded',
            'cursor' => $page['cursor'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $blocks[] = "event: stop\n".'data: '.$stop;

        return response(implode("\n\n", $blocks)."\n\n", Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
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
}
