<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class DashboardWikiRefreshController extends Controller
{
    use ChecksDashboardRoles;

    public function index(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessReader($request);
        abort_unless(DB::table('projects')->where('id', $project)->where('status', '!=', 'deleted')->exists(), 404);

        $requests = DB::table('hades_agent_jobs')
            ->where('project_id', $project)
            ->where('capability', 'populate_project_wiki')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $job): array => $this->refreshPayload($job))
            ->all();

        return response()->json(['refresh_requests' => $requests]);
    }

    public function store(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessMutator($request);
        abort_unless(DB::table('projects')->where('id', $project)->where('status', 'active')->exists(), 404);

        $validated = $request->validate([
            'workspace_binding_id' => [
                'required',
                'string',
                Rule::exists('hades_workspace_bindings', 'id')
                    ->where(fn ($query) => $query->where('project_id', $project)->where('status', 'linked')),
            ],
            'repository_id' => [
                'nullable',
                'string',
                Rule::exists('repositories', 'id')->where(fn ($query) => $query->where('project_id', $project)),
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $validated['workspace_binding_id'])
            ->where('project_id', $project)
            ->where('status', 'linked')
            ->first();

        abort_unless($binding, 404);

        $jobId = (string) Str::ulid();
        $now = now();
        $payload = [
            'schema' => 'devboard.wiki_refresh_request.v1',
            'project_id' => $project,
            'repository_id' => $validated['repository_id'] ?? null,
            'workspace_binding_id' => $binding->id,
            'reason' => $validated['reason'] ?? null,
            'requested_by_user_id' => $request->user()->id,
            'requested_at' => $now->toISOString(),
        ];

        DB::table('hades_agent_jobs')->insert([
            'id' => $jobId,
            'project_id' => $project,
            'repository_id' => $validated['repository_id'] ?? null,
            'hades_agent_id' => $binding->hades_agent_id,
            'workspace_binding_id' => $binding->id,
            'requested_by_user_id' => $request->user()->id,
            'idempotency_key' => $validated['idempotency_key'] ?? 'wiki-refresh-'.$jobId,
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
            'result_applied_at' => null,
            'error_code' => null,
            'error_message' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $job = DB::table('hades_agent_jobs')->where('id', $jobId)->first();

        return response()->json(['refresh_request' => $this->refreshPayload($job)], Response::HTTP_CREATED);
    }

    private function refreshPayload(object $job): array
    {
        return [
            'id' => (string) $job->id,
            'project_id' => (string) $job->project_id,
            'repository_id' => $job->repository_id ? (string) $job->repository_id : null,
            'hades_agent_id' => $job->hades_agent_id ? (string) $job->hades_agent_id : null,
            'workspace_binding_id' => (string) $job->workspace_binding_id,
            'capability' => (string) $job->capability,
            'status' => (string) $job->status,
            'policy' => (string) $job->policy,
            'priority' => (string) $job->priority,
            'payload' => $this->decode($job->payload),
            'result' => $this->decode($job->result),
            'result_applied_at' => $job->result_applied_at ? (string) $job->result_applied_at : null,
            'created_at' => (string) $job->created_at,
            'completed_at' => $job->completed_at ? (string) $job->completed_at : null,
            'failed_at' => $job->failed_at ? (string) $job->failed_at : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function abortUnlessReader(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }

    private function abortUnlessMutator(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }
}
