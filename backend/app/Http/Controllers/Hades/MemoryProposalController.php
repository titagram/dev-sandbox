<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MemoryProposalController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'local_proposal_id' => ['nullable', 'string', 'max:191'],
            'action' => ['required', 'string', 'in:create,update,delete'],
            'intent' => ['required', 'string', 'max:191'],
            'summary' => ['required', 'string', 'max:4000'],
            'provenance' => ['nullable', 'array'],
            'base_version' => ['nullable', 'string', 'max:191'],
            'memory_entry_id' => ['nullable', 'string'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        if (($validated['local_proposal_id'] ?? null) !== null) {
            $existing = DB::table('hades_memory_proposals')
                ->where('workspace_binding_id', $binding->id)
                ->where('local_proposal_id', $validated['local_proposal_id'])
                ->first();

            if ($existing) {
                return response()->json($this->proposalPayload($existing));
            }
        }

        $now = now();
        $proposal = DB::transaction(function () use ($agent, $binding, $now, $validated) {
            $memoryEntryId = null;
            $status = 'pending';
            $reasonCode = null;
            $reasonMessage = null;
            $decidedAt = null;

            if ($validated['action'] === 'create' && $this->shouldAutoAcceptCreate($validated)) {
                $memoryEntryId = (string) Str::ulid();
                $status = 'accepted';
                $decidedAt = $now;

                DB::table('project_memory_entries')->insert([
                    'id' => $memoryEntryId,
                    'project_id' => $validated['project_id'],
                    'repository_id' => null,
                    'task_id' => null,
                    'run_id' => null,
                    'author_user_id' => null,
                    'agent_key' => $agent->external_agent_id,
                    'source' => 'hades_agent',
                    'kind' => 'proposal',
                    'completeness' => 'complete',
                    'summary' => $validated['summary'],
                    'payload' => json_encode([
                        'schema' => 'hades.memory_proposal.v1',
                        'action' => $validated['action'],
                        'intent' => $validated['intent'],
                        'workspace_binding_id' => $binding->id,
                        'local_proposal_id' => $validated['local_proposal_id'] ?? null,
                        'provenance' => $validated['provenance'] ?? [],
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ($validated['action'] === 'create') {
                $reasonCode = 'manual_review_required';
                $reasonMessage = 'This memory proposal must be reviewed before it can become project memory.';
            }

            $proposalId = (string) Str::ulid();
            DB::table('hades_memory_proposals')->insert([
                'id' => $proposalId,
                'project_id' => $validated['project_id'],
                'hades_agent_id' => $agent->id,
                'workspace_binding_id' => $binding->id,
                'local_proposal_id' => $validated['local_proposal_id'] ?? null,
                'action' => $validated['action'],
                'intent' => $validated['intent'],
                'summary' => $validated['summary'],
                'provenance' => json_encode($validated['provenance'] ?? [], JSON_THROW_ON_ERROR),
                'base_version' => $validated['base_version'] ?? null,
                'target_memory_entry_id' => $validated['memory_entry_id'] ?? null,
                'memory_entry_id' => $memoryEntryId,
                'status' => $status,
                'reason_code' => $reasonCode,
                'reason_message' => $reasonMessage,
                'decided_at' => $decidedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('hades_memory_proposals')->where('id', $proposalId)->first();
        });

        return response()->json($this->proposalPayload($proposal));
    }

    private function shouldAutoAcceptCreate(array $validated): bool
    {
        return ! in_array($validated['intent'], [
            'note_backfill_candidate',
        ], true);
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

    private function proposalPayload(object $proposal): array
    {
        return [
            'protocol_version' => 'v1',
            'project_id' => $proposal->project_id,
            'workspace_binding_id' => $proposal->workspace_binding_id,
            'proposal' => [
                'id' => $proposal->id,
                'local_proposal_id' => $proposal->local_proposal_id,
                'action' => $proposal->action,
                'intent' => $proposal->intent,
                'status' => $proposal->status,
                'reason_code' => $proposal->reason_code,
                'reason_message' => $proposal->reason_message,
                'memory_entry_id' => $proposal->memory_entry_id,
            ],
            'server_time' => now()->toISOString(),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
