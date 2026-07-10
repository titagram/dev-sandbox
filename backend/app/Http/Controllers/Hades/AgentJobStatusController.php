<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesAgentJobPolicy;
use App\Services\Hades\HadesJobException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AgentJobStatusController extends Controller
{
    public function __construct(private readonly HadesAgentJobPolicy $jobs) {}

    public function __invoke(Request $request, string $job): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
            'workspace_binding_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:received,waiting_confirmation,started,failed,expired,cancelled,unlinked'],
            'reason' => ['nullable', 'string', 'max:4000'],
            'error' => ['nullable', 'string', 'max:4000'],
            'payload' => ['nullable', 'array'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id'], $validated['agent_id'] ?? null);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $now = now();
        $status = $validated['status'];
        try {
            $updated = DB::transaction(function () use ($agent, $binding, $job, $status, $validated, $now): object {
                $this->assertBindingStillLinked($agent, $binding->id, $validated['project_id']);
                $currentAgent = DB::table('hades_agents')->where('id', $agent->id)->lockForUpdate()->first();
                $record = $this->lockedJob($agent, $job, $validated['project_id'], $binding->id);
                $this->jobs->assertCapability($currentAgent, $record);

                if ($status === 'started'
                    && $record->status === 'started'
                    && (bool) $record->requires_confirmation
                    && DB::table('hades_agent_job_events')
                        ->where('job_id', $job)
                        ->where('event_type', 'confirmation')
                        ->where('status', 'started')
                        ->exists()) {
                    return $record;
                }

                $this->jobs->assertStatusTransition($record, $status);

                $updates = [
                    'hades_agent_id' => $agent->id,
                    'status' => $status,
                    'updated_at' => $now,
                ];
                if (in_array($status, ['received', 'waiting_confirmation'], true)) {
                    $updates['claimed_at'] = $record->claimed_at ?: $now;
                }
                if ($status === 'started') {
                    $updates['claimed_at'] = $record->claimed_at ?: $now;
                    $updates['started_at'] = $record->started_at ?: $now;
                }
                if ($status === 'failed') {
                    $updates['failed_at'] = $now;
                    $updates['error_message'] = $validated['error'] ?? $validated['reason'] ?? null;
                }
                if ($status === 'cancelled') {
                    $updates['cancelled_at'] = $now;
                }

                $affected = DB::table('hades_agent_jobs')
                    ->where('id', $job)
                    ->where('status', $record->status)
                    ->where(function ($query) use ($agent): void {
                        $query->whereNull('hades_agent_id')->orWhere('hades_agent_id', $agent->id);
                    })
                    ->update($updates);
                if ($affected !== 1) {
                    throw new HadesJobException('job_concurrent_update', 'The Hades job was updated concurrently.', Response::HTTP_CONFLICT);
                }

                DB::table('hades_agent_job_events')->insert([
                    'id' => (string) Str::ulid(),
                    'job_id' => $job,
                    'event_type' => 'status',
                    'status' => $status,
                    'payload' => json_encode([
                        'reason' => $validated['reason'] ?? null,
                        'error' => $validated['error'] ?? null,
                        'payload' => $validated['payload'] ?? [],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return DB::table('hades_agent_jobs')->where('id', $job)->first();
            }, 3);
        } catch (HadesJobException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $updated->project_id,
            'workspace_binding_id' => $updated->workspace_binding_id,
            'job' => $this->payload($updated),
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

    private function lockedJob(object $agent, string $jobId, string $projectId, string $bindingId): object
    {
        $job = DB::table('hades_agent_jobs')
            ->where('id', $jobId)
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $bindingId)
            ->where(function ($query) use ($agent): void {
                $query->whereNull('hades_agent_id')->orWhere('hades_agent_id', $agent->id);
            })
            ->lockForUpdate()
            ->first();

        if (! $job) {
            throw new HadesJobException('job_not_found', 'Hades agent job was not found.', Response::HTTP_NOT_FOUND);
        }

        return $job;
    }

    private function assertBindingStillLinked(object $agent, string $bindingId, string $projectId): void
    {
        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $bindingId)
            ->where('project_id', $projectId)
            ->where('hades_agent_id', $agent->id)
            ->lockForUpdate()
            ->first();

        if (! $binding || $binding->status !== 'linked') {
            throw new HadesJobException('workspace_binding_unlinked', 'Workspace binding is not linked.', Response::HTTP_CONFLICT);
        }
    }

    private function payload(object $job): array
    {
        return [
            'job_id' => $job->id,
            'id' => $job->id,
            'capability' => $job->capability,
            'status' => $job->status,
            'policy' => $job->policy,
            'payload' => $this->decode($job->payload),
            'started_at' => $this->toIsoString($job->started_at),
            'completed_at' => $this->toIsoString($job->completed_at),
            'failed_at' => $this->toIsoString($job->failed_at),
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
