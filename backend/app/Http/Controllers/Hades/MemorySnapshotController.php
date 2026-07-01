<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MemorySnapshotController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $limit = (int) ($validated['limit'] ?? 200);
        $entries = DB::table('project_memory_entries')
            ->where('project_id', $validated['project_id'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $items = $entries->map(fn (object $entry): array => $this->entryPayload($entry))->values()->all();
        $version = $this->snapshotVersion($validated['project_id'], $binding->id, $entries->all());

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'version' => $version,
            'snapshot_version' => $version,
            'etag' => $version,
            'items' => $items,
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

    private function entryPayload(object $entry): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'source' => $entry->source,
            'summary' => $entry->summary,
            'payload' => $this->decodePayload($entry->payload),
            'occurred_at' => $this->toIsoString($entry->occurred_at),
            'updated_at' => $this->toIsoString($entry->updated_at),
            'version' => 'mem_'.hash('sha256', $entry->id.'|'.$entry->updated_at),
        ];
    }

    private function snapshotVersion(string $projectId, string $bindingId, array $entries): string
    {
        $material = [$projectId, $bindingId, (string) count($entries)];
        foreach ($entries as $entry) {
            $material[] = $entry->id.':'.$entry->updated_at;
        }

        return 'snapshot_'.hash('sha256', implode('|', $material));
    }

    private function decodePayload(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

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
