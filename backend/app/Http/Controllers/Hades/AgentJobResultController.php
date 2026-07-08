<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
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

        $record = $this->jobRecord($agent, $job, $validated['project_id'], $binding->id);

        if ($record instanceof JsonResponse) {
            return $record;
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

        try {
            DB::transaction(function () use ($job, $status, &$updates, &$result, $record, $wikiResults, $now): void {
                if ($status === 'completed' && $this->shouldApplyWikiRefreshResult($record, $result)) {
                    $result['applied'] = $wikiResults->apply($record, $result);
                    $updates['result'] = json_encode($result, JSON_THROW_ON_ERROR);
                    $updates['result_applied_at'] = $now;
                }

                DB::table('hades_agent_jobs')->where('id', $job)->update($updates);
                DB::table('hades_agent_job_events')->insert([
                    'id' => (string) Str::ulid(),
                    'job_id' => $job,
                    'event_type' => 'result',
                    'status' => $status,
                    'payload' => json_encode(['result' => $result], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (WikiRevisionException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $updated = DB::table('hades_agent_jobs')->where('id', $job)->first();

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

    private function jobRecord(object $agent, string $jobId, string $projectId, string $bindingId): mixed
    {
        $job = DB::table('hades_agent_jobs')
            ->where('id', $jobId)
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $bindingId)
            ->where(function ($query) use ($agent): void {
                $query->whereNull('hades_agent_id')->orWhere('hades_agent_id', $agent->id);
            })
            ->first();

        return $job ?: $this->error('job_not_found', 'Hades agent job was not found.', Response::HTTP_NOT_FOUND);
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
