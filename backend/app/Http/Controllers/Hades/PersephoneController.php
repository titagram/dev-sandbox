<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PersephoneController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['nullable', 'string'],
            'event_type' => ['required', 'string', 'max:191'],
            'payload' => ['nullable', 'array'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->optionalBinding($agent, $validated['project_id'], $validated['workspace_binding_id'] ?? null);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $event = $this->createEvent($validated['project_id'], $agent->id, $binding?->id, $validated['event_type'], $validated['payload'] ?? []);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'event' => $this->payload($event),
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    public function inbox(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];

        if ($agent->project_id !== $validated['project_id']) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        $limit = (int) ($validated['limit'] ?? 50);
        $base = DB::table('hades_persephone_events')
            ->where('project_id', $validated['project_id'])
            ->where(function ($query) use ($agent): void {
                $query->whereNull('hades_agent_id')->orWhere('hades_agent_id', $agent->id);
            });

        $events = (clone $base)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $event): array => $this->payload($event))
            ->values()
            ->all();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'counts' => [
                'total' => (clone $base)->count(),
                'unread' => (clone $base)->whereNull('read_at')->count(),
            ],
            'events' => $events,
            'server_time' => now()->toISOString(),
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

    private function optionalBinding(object $agent, string $projectId, ?string $bindingId): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        if ($bindingId === null) {
            return null;
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $bindingId)
            ->where('project_id', $projectId)
            ->where('hades_agent_id', $agent->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        return $binding;
    }

    private function createEvent(string $projectId, ?string $agentId, ?string $bindingId, string $eventType, array $payload): object
    {
        $id = (string) Str::ulid();
        $now = now();

        DB::table('hades_persephone_events')->insert([
            'id' => $id,
            'project_id' => $projectId,
            'hades_agent_id' => $agentId,
            'workspace_binding_id' => $bindingId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('hades_persephone_events')->where('id', $id)->first();
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

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
