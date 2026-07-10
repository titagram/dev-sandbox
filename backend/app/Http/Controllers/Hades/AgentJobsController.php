<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesAgentJobPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AgentJobsController extends Controller
{
    public function __construct(private readonly HadesAgentJobPolicy $jobs) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
            'workspace_binding_id' => ['required', 'string'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', 'max:191'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id'], $validated['agent_id'] ?? null);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $effectiveCapabilities = $this->jobs->effectiveCapabilities($agent);
        $requestedCapabilities = array_values(array_filter($validated['capabilities'] ?? [], 'is_string'));
        $capabilities = $requestedCapabilities === []
            ? $effectiveCapabilities
            : array_values(array_intersect($effectiveCapabilities, $requestedCapabilities));
        $limit = (int) ($validated['limit'] ?? 25);
        $now = now();
        $query = DB::table('hades_agent_jobs')
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->where('status', 'queued')
            ->where(function ($query) use ($agent): void {
                $query->whereNull('hades_agent_id')->orWhere('hades_agent_id', $agent->id);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('deadline_at')->orWhere('deadline_at', '>=', $now);
            })
            ->whereIn('capability', $capabilities)
            ->orderBy('created_at')
            ->limit($limit);

        $jobs = $capabilities === []
            ? []
            : $query->get()->map(fn (object $job): array => $this->payload($job))->values()->all();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'agent_id' => $agent->external_agent_id,
            'backend_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'jobs' => $jobs,
            'server_time' => now()->toISOString(),
        ]);
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
        $policy = $job->policy ?: 'auto';

        return [
            'job_id' => $job->id,
            'id' => $job->id,
            'project_id' => $job->project_id,
            'workspace_binding_id' => $job->workspace_binding_id,
            'capability' => $job->capability,
            'status' => $job->status,
            'policy' => $policy,
            'execution_policy' => $policy,
            'requires_confirmation' => $this->jobs->requiresConfirmation($job),
            'payload' => $this->decode($job->payload),
            'deadline_at' => $this->toIsoString($job->deadline_at),
        ];
    }

    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

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
