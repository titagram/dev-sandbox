<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ProjectAwarenessBootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
            'workspace_binding_id' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id'], $validated['agent_id'] ?? null);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $active = DB::table('hades_agent_jobs')
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->where('hades_agent_id', $agent->id)
            ->where('capability', 'populate_project_wiki')
            ->whereIn('status', ['queued', 'received', 'waiting_confirmation', 'started'])
            ->orderByDesc('created_at')
            ->first();

        if ($active) {
            return response()->json([
                'protocol_version' => 'v1',
                'project_id' => $validated['project_id'],
                'workspace_binding_id' => $binding->id,
                'job' => $this->payload($active),
                'created' => false,
                'server_time' => now()->toISOString(),
            ]);
        }

        $jobId = (string) Str::ulid();
        $now = now();
        $payload = [
            'schema' => 'devboard.wiki_refresh_request.v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'reason' => $validated['reason'] ?? 'Hades CLI project awareness bootstrap',
            'requested_by_agent_id' => $agent->id,
            'requested_at' => $now->toISOString(),
        ];

        DB::table('hades_agent_jobs')->insert([
            'id' => $jobId,
            'project_id' => $validated['project_id'],
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'idempotency_key' => $validated['idempotency_key'] ?? 'awareness-bootstrap-'.$binding->id.'-'.$jobId,
            'capability' => 'populate_project_wiki',
            'job_type' => 'wiki_refresh',
            'status' => 'queued',
            'policy' => 'auto',
            'priority' => $validated['priority'] ?? 'normal',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'result' => null,
            'requires_confirmation' => false,
            'deadline_at' => null,
            'available_at' => null,
            'claimed_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'error_code' => null,
            'error_message' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $job = DB::table('hades_agent_jobs')->where('id', $jobId)->first();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'job' => $this->payload($job),
            'created' => true,
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId, ?string $externalAgentId): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        if ($externalAgentId !== null && $externalAgentId !== $agent->external_agent_id) {
            return $this->error('agent_mismatch', 'Hades agent token is scoped to a different external agent.', Response::HTTP_FORBIDDEN);
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

    private function payload(object $job): array
    {
        return [
            'job_id' => $job->id,
            'id' => $job->id,
            'project_id' => $job->project_id,
            'workspace_binding_id' => $job->workspace_binding_id,
            'capability' => $job->capability,
            'status' => $job->status,
            'policy' => $job->policy ?: 'auto',
            'priority' => $job->priority ?: 'normal',
            'requires_confirmation' => (bool) $job->requires_confirmation,
            'payload' => $this->decode($job->payload),
            'created_at' => $job->created_at,
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
