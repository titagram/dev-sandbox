<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesAgentJobPolicy;
use App\Services\Hades\HadesJobException;
use App\Services\WikiRefreshResultService;
use App\Services\WikiRevisionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AgentJobResultController extends Controller
{
    public function __construct(private readonly HadesAgentJobPolicy $jobs) {}

    public function __invoke(Request $request, WikiRefreshResultService $wikiResults, string $job): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
            'workspace_binding_id' => ['required', 'string'],
            'status' => ['nullable', 'string', 'in:completed,failed'],
            'result' => ['nullable', 'array'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'error' => ['nullable', 'string', 'max:4000'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id'], $validated['agent_id'] ?? null);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $result = $validated['result'] ?? [];
        if (($validated['summary'] ?? null) !== null && ! isset($result['summary'])) {
            $result['summary'] = $validated['summary'];
        }

        $status = $validated['status'] ?? ($result['status'] ?? 'completed');
        if (! in_array($status, ['completed', 'failed'], true)) {
            $status = 'completed';
        }

        $now = now();
        try {
            $updated = DB::transaction(function () use ($agent, $binding, $job, $status, $validated, &$result, $wikiResults, $now): object {
                $this->assertBindingStillLinked($agent, $binding->id, $validated['project_id']);
                $currentAgent = DB::table('hades_agents')->where('id', $agent->id)->lockForUpdate()->first();
                $record = $this->lockedJob($agent, $job, $validated['project_id'], $binding->id);
                $this->jobs->assertCapability($currentAgent, $record);
                $this->jobs->assertResult($record, $status, $result);

                $expectedStatus = (string) $record->status;
                $transitionRecord = clone $record;
                foreach ($this->jobs->resultPreparationStatuses($record) as $preparationStatus) {
                    $this->jobs->assertStatusTransition($transitionRecord, $preparationStatus);
                    $transitionUpdates = [
                        'hades_agent_id' => $agent->id,
                        'status' => $preparationStatus,
                        'updated_at' => $now,
                    ];
                    if ($preparationStatus === 'received') {
                        $transitionUpdates['claimed_at'] = $transitionRecord->claimed_at ?: $now;
                    }
                    if ($preparationStatus === 'started') {
                        $transitionUpdates['claimed_at'] = $transitionRecord->claimed_at ?: $now;
                        $transitionUpdates['started_at'] = $transitionRecord->started_at ?: $now;
                    }

                    $advanced = DB::table('hades_agent_jobs')
                        ->where('id', $job)
                        ->where('status', $expectedStatus)
                        ->where(function ($query) use ($agent): void {
                            $query->whereNull('hades_agent_id')->orWhere('hades_agent_id', $agent->id);
                        })
                        ->update($transitionUpdates);
                    if ($advanced !== 1) {
                        throw new HadesJobException('job_concurrent_update', 'The Hades job was updated concurrently.', Response::HTTP_CONFLICT);
                    }

                    DB::table('hades_agent_job_events')->insert([
                        'id' => (string) Str::ulid(),
                        'job_id' => $job,
                        'event_type' => 'status',
                        'status' => $preparationStatus,
                        'payload' => json_encode([
                            'reason' => 'implicit_wiki_result_submission',
                            'error' => null,
                            'payload' => [],
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $expectedStatus = $preparationStatus;
                    $transitionRecord->status = $preparationStatus;
                    if ($preparationStatus === 'received') {
                        $transitionRecord->claimed_at = $transitionUpdates['claimed_at'];
                    }
                    if ($preparationStatus === 'started') {
                        $transitionRecord->started_at = $transitionUpdates['started_at'];
                    }
                }

                $updates = [
                    'hades_agent_id' => $agent->id,
                    'status' => $status,
                    'result' => json_encode($result, JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ];
                if ($status === 'completed') {
                    $updates['completed_at'] = $now;
                } else {
                    $updates['failed_at'] = $now;
                    $updates['error_message'] = $validated['error'] ?? ($result['summary'] ?? null);
                }

                if ($status === 'completed' && $this->shouldApplyWikiRefreshResult($record, $result)) {
                    $result['applied'] = $wikiResults->apply($record, $result);
                    $updates['result'] = json_encode($result, JSON_THROW_ON_ERROR);
                    $updates['result_applied_at'] = $now;
                }

                $affected = DB::table('hades_agent_jobs')
                    ->where('id', $job)
                    ->where('status', $expectedStatus)
                    ->where('hades_agent_id', $agent->id)
                    ->update($updates);
                if ($affected !== 1) {
                    throw new HadesJobException('job_concurrent_update', 'The Hades job was updated concurrently.', Response::HTTP_CONFLICT);
                }

                DB::table('hades_agent_job_events')->insert([
                    'id' => (string) Str::ulid(),
                    'job_id' => $job,
                    'event_type' => 'result',
                    'status' => $status,
                    'payload' => json_encode(['result' => $result], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return DB::table('hades_agent_jobs')->where('id', $job)->first();
            }, 3);
        } catch (WikiRevisionException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
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
            'result' => $this->decode($job->result),
            'completed_at' => $this->toIsoString($job->completed_at),
            'failed_at' => $this->toIsoString($job->failed_at),
            'result_applied_at' => $this->toIsoString($job->result_applied_at ?? null),
        ];
    }

    private function shouldApplyWikiRefreshResult(object $job, array $result): bool
    {
        return $job->capability === 'populate_project_wiki'
            || ($result['schema'] ?? null) === 'devboard.wiki_refresh_result.v1';
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
