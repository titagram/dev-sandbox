<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\Hades\HadesTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class DashboardHadesController extends Controller
{
    use ChecksDashboardRoles;

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        return response()->json([
            'projects' => DB::table('projects')
                ->select(['id', 'name', 'slug'])
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(100)
                ->get(),
            'bootstrapTokens' => DB::table('hades_bootstrap_tokens')
                ->join('projects', 'projects.id', '=', 'hades_bootstrap_tokens.project_id')
                ->select([
                    'hades_bootstrap_tokens.id',
                    'hades_bootstrap_tokens.project_id',
                    'projects.name as project_name',
                    'hades_bootstrap_tokens.token_prefix',
                    'hades_bootstrap_tokens.name',
                    'hades_bootstrap_tokens.expires_at',
                    'hades_bootstrap_tokens.revoked_at',
                    'hades_bootstrap_tokens.last_used_at',
                    'hades_bootstrap_tokens.created_at',
                ])
                ->orderByDesc('hades_bootstrap_tokens.created_at')
                ->limit(100)
                ->get(),
            'workspaces' => DB::table('hades_workspace_bindings')
                ->join('projects', 'projects.id', '=', 'hades_workspace_bindings.project_id')
                ->join('hades_agents', 'hades_agents.id', '=', 'hades_workspace_bindings.hades_agent_id')
                ->select([
                    'hades_workspace_bindings.id',
                    'hades_workspace_bindings.project_id',
                    'projects.name as project_name',
                    'hades_workspace_bindings.display_path',
                    'hades_workspace_bindings.status',
                    'hades_workspace_bindings.updated_at',
                    'hades_agents.label as agent_label',
                ])
                ->orderByDesc('hades_workspace_bindings.updated_at')
                ->limit(100)
                ->get(),
            'jobs' => DB::table('hades_agent_jobs')
                ->select(['id', 'project_id', 'workspace_binding_id', 'capability', 'status', 'policy', 'requires_confirmation', 'created_at', 'completed_at', 'failed_at', 'cancelled_at'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'memoryProposals' => DB::table('hades_memory_proposals')
                ->select(['id', 'project_id', 'workspace_binding_id', 'action', 'intent', 'summary', 'status', 'reason_code', 'created_at', 'decided_at'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
        ]);
    }

    public function createBootstrapToken(Request $request, HadesTokenService $tokens): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate([
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'allowed_capabilities' => ['nullable', 'array'],
            'allowed_capabilities.*' => ['string', 'in:read_files,read_source_slice,sync_git_tree,populate_backend_ast,populate_project_wiki'],
            'base_url' => ['nullable', 'url'],
            'project_name' => ['nullable', 'string', 'max:255'],
        ]);

        $created = $tokens->createBootstrapToken(
            $validated['project_id'],
            $validated['name'],
            $validated['expires_in_days'] ?? 90,
            $validated['allowed_capabilities'] ?? null,
        );

        $baseUrl = rtrim($validated['base_url'] ?? $request->getSchemeAndHttpHost(), '/');

        return response()->json([
            'plain_token' => $created['plain_token'],
            'token' => $this->bootstrapTokenPayload($created['token']),
            'install' => $this->installCommands(
                $baseUrl,
                $validated['project_id'],
                $created['plain_token'],
                $validated['project_name'] ?? null,
            ),
        ], Response::HTTP_CREATED);
    }

    public function revokeBootstrapToken(Request $request, string $token): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        abort_unless(DB::table('hades_bootstrap_tokens')->where('id', $token)->exists(), 404);

        DB::table('hades_bootstrap_tokens')
            ->where('id', $token)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['revoked' => true]);
    }

    public function createJob(Request $request): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate([
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'workspace_binding_id' => ['required', 'string', 'exists:hades_workspace_bindings,id'],
            'hades_agent_id' => ['nullable', 'string', 'exists:hades_agents,id'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            'capability' => ['required', 'string', 'in:read_files,read_source_slice,sync_git_tree,populate_backend_ast,populate_project_wiki'],
            'policy' => ['nullable', 'string', 'max:191'],
            'priority' => ['nullable', 'string', 'max:191'],
            'payload' => ['required', 'array'],
            'requires_confirmation' => ['nullable', 'boolean'],
            'deadline_at' => ['nullable', 'date'],
            'available_at' => ['nullable', 'date'],
        ]);

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $validated['workspace_binding_id'])
            ->where('project_id', $validated['project_id'])
            ->where('status', 'linked')
            ->first();

        abort_unless($binding, 404);

        $id = (string) Str::ulid();
        $now = now();

        DB::table('hades_agent_jobs')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'hades_agent_id' => $validated['hades_agent_id'] ?? $binding->hades_agent_id,
            'workspace_binding_id' => $binding->id,
            'idempotency_key' => $validated['idempotency_key'] ?? null,
            'capability' => $validated['capability'],
            'status' => 'queued',
            'policy' => $validated['policy'] ?? 'manual_review',
            'priority' => $validated['priority'] ?? 'normal',
            'payload' => json_encode($validated['payload'], JSON_THROW_ON_ERROR),
            'result' => null,
            'requires_confirmation' => (bool) ($validated['requires_confirmation'] ?? false),
            'deadline_at' => $validated['deadline_at'] ?? null,
            'available_at' => $validated['available_at'] ?? null,
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

        $job = DB::table('hades_agent_jobs')->where('id', $id)->first();

        return response()->json(['job' => $this->jobPayload($job)], Response::HTTP_CREATED);
    }

    public function reviewMemoryProposal(Request $request, string $proposal): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:accepted,refused,conflicted'],
            'reason_code' => ['nullable', 'string', 'max:191'],
            'reason_message' => ['nullable', 'string', 'max:4000'],
        ]);

        abort_unless(DB::table('hades_memory_proposals')->where('id', $proposal)->exists(), 404);

        $row = DB::transaction(function () use ($proposal, $validated) {
            $now = now();
            $row = DB::table('hades_memory_proposals')->where('id', $proposal)->first();
            $memoryEntryId = $row->memory_entry_id;

            if ($validated['status'] === 'accepted'
                && $row->action === 'create'
                && $memoryEntryId === null) {
                $memoryEntryId = $this->createProjectMemoryFromProposal($row, $now);
            }

            DB::table('hades_memory_proposals')->where('id', $proposal)->update([
                'memory_entry_id' => $memoryEntryId,
                'status' => $validated['status'],
                'reason_code' => $validated['reason_code'] ?? null,
                'reason_message' => $validated['reason_message'] ?? null,
                'decided_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('hades_memory_proposals')->where('id', $proposal)->first();
        });

        return response()->json([
            'proposal' => [
                'id' => $row->id,
                'project_id' => $row->project_id,
                'workspace_binding_id' => $row->workspace_binding_id,
                'status' => $row->status,
                'reason_code' => $row->reason_code,
                'reason_message' => $row->reason_message,
                'memory_entry_id' => $row->memory_entry_id,
                'decided_at' => $row->decided_at,
            ],
        ]);
    }

    private function createProjectMemoryFromProposal(object $proposal, mixed $now): string
    {
        $memoryEntryId = (string) Str::ulid();
        $provenance = json_decode((string) $proposal->provenance, true);
        $provenance = is_array($provenance) ? $provenance : [];
        $agentKey = DB::table('hades_agents')->where('id', $proposal->hades_agent_id)->value('external_agent_id');
        $binding = DB::table('hades_workspace_bindings')->where('id', $proposal->workspace_binding_id)->first();
        $workspaceHeadCommit = trim((string) ($binding->head_commit ?? ''));
        $reviewedAt = method_exists($now, 'toISOString') ? $now->toISOString() : (string) $now;

        DB::table('project_memory_entries')->insert([
            'id' => $memoryEntryId,
            'project_id' => $proposal->project_id,
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => $agentKey,
            'source' => 'hades_agent',
            'kind' => $proposal->intent === 'note_backfill_candidate' ? 'verified_note_fact' : 'proposal',
            'completeness' => 'complete',
            'summary' => $proposal->summary,
            'payload' => json_encode([
                'schema' => 'hades.memory_proposal.v1',
                'action' => $proposal->action,
                'intent' => $proposal->intent,
                'workspace_binding_id' => $proposal->workspace_binding_id,
                'local_proposal_id' => $proposal->local_proposal_id,
                'provenance' => $provenance,
                'review' => [
                    'status' => 'accepted',
                    'reviewed_from' => 'dashboard',
                ],
                'freshness' => [
                    'status' => $workspaceHeadCommit !== '' ? 'current' : 'unknown',
                    'workspace_head_commit' => $workspaceHeadCommit !== '' ? $workspaceHeadCommit : null,
                    'index_status' => 'reviewed_note_fact',
                    'reviewed_at' => $reviewedAt,
                ],
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $memoryEntryId;
    }

    private function bootstrapTokenPayload(object $token): array
    {
        return [
            'id' => $token->id,
            'project_id' => $token->project_id,
            'token_prefix' => $token->token_prefix,
            'name' => $token->name,
            'scopes' => json_decode($token->scopes, true, 512, JSON_THROW_ON_ERROR),
            'allowed_capabilities' => $token->allowed_capabilities ? json_decode($token->allowed_capabilities, true, 512, JSON_THROW_ON_ERROR) : null,
            'expires_at' => $token->expires_at,
            'revoked_at' => $token->revoked_at,
            'last_used_at' => $token->last_used_at,
        ];
    }

    private function installCommands(string $baseUrl, string $projectId, string $token, ?string $projectName): array
    {
        $posix = 'curl -fsSL '.$this->shQuote($baseUrl.'/install.sh').' | bash -s -- --backend-url '.$this->shQuote($baseUrl)
            .' --backend-project-id '.$projectId.' --backend-project-token '.$this->shQuote($token);
        $windows = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "iwr -UseB '.$baseUrl.'/install.ps1 -OutFile install.ps1; .\\install.ps1 -BackendUrl '.$baseUrl
            .' -BackendProjectId '.$projectId.' -BackendProjectToken '.$this->psQuote($token);

        if ($projectName !== null && $projectName !== '') {
            $posix .= ' --backend-project-name '.$this->shQuote($projectName);
            $windows .= ' -BackendProjectName '.$this->psQuote($projectName);
        }

        $windows .= '"';

        return [
            'posix' => $posix,
            'windows' => $windows,
        ];
    }

    private function shQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function psQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function jobPayload(object $job): array
    {
        return [
            'id' => $job->id,
            'project_id' => $job->project_id,
            'hades_agent_id' => $job->hades_agent_id,
            'workspace_binding_id' => $job->workspace_binding_id,
            'capability' => $job->capability,
            'status' => $job->status,
            'policy' => $job->policy,
            'requires_confirmation' => (bool) $job->requires_confirmation,
            'payload' => json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function abortUnlessAdmin(Request $request): void
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);
    }
}
