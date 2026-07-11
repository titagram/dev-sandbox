<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Plugin\Concerns\HandlesRunResponses;
use App\Projects\ProjectLifecycleService;
use App\Services\PluginInvariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunStartController extends Controller
{
    use HandlesRunResponses;

    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginInvariantService $invariants,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'repository_id' => ['nullable', 'string', 'exists:repositories,id'],
            'local_workspace_id' => ['nullable', 'string', 'exists:local_workspaces,id'],
            'task_id' => ['nullable', 'string', 'exists:tasks,id'],
            'run_type' => ['required', 'string', 'max:255'],
            'runtime_profile' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:255'],
            'base_branch' => ['required', 'string', 'max:255'],
            'base_sha' => ['required', 'string', 'max:255'],
            'head_sha' => ['nullable', 'string', 'max:255'],
            'dirty_status' => ['required', 'string', 'max:64'],
        ]);

        if ($error = $this->lifecycle->pluginProjectWriteGuard($validated['project_id'])) {
            return $error;
        }

        if ($error = $this->invariants->assertAuthenticatedDevice($request)) {
            return $error;
        }

        $auth = $request->attributes->get('plugin_auth');
        $repository = isset($validated['repository_id'])
            ? DB::table('repositories')->where('id', $validated['repository_id'])->first()
            : null;
        $workspace = isset($validated['local_workspace_id'])
            ? DB::table('local_workspaces')->where('id', $validated['local_workspace_id'])->first()
            : null;
        $task = isset($validated['task_id'])
            ? DB::table('tasks')->where('id', $validated['task_id'])->first()
            : null;
        $referencesAreConsistent = (! $repository || (string) $repository->project_id === (string) $validated['project_id'])
            && (! $workspace || ($repository
                && (string) $workspace->repository_id === (string) $repository->id
                && (string) $workspace->device_id === (string) $auth['token']->device_id))
            && (! $task || (string) $task->project_id === (string) $validated['project_id']);

        if ($error = $this->invariants->assertReferences(
            $referencesAreConsistent,
            'Run project, repository, workspace, task, and device references are inconsistent.',
        )) {
            return $error;
        }

        $now = now();
        $runId = (string) Str::ulid();

        DB::table('runs')->insert([
            'id' => $runId,
            'project_id' => $validated['project_id'],
            'repository_id' => $validated['repository_id'] ?? null,
            'local_workspace_id' => $validated['local_workspace_id'] ?? null,
            'task_id' => $validated['task_id'] ?? null,
            'device_id' => $auth['token']->device_id,
            'started_by_user_id' => $auth['token']->user_id,
            'runtime_profile' => $validated['runtime_profile'],
            'status' => 'started',
            'branch' => $validated['branch'],
            'base_branch' => $validated['base_branch'],
            'base_sha' => $validated['base_sha'],
            'head_sha' => $validated['head_sha'] ?? null,
            'summary' => null,
            'risk_level' => 'low',
            'started_at' => $now,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->appendRunEvent($runId, 'run.started', 'info', 'Run started.', [
            'run_type' => $validated['run_type'],
            'dirty_status' => $validated['dirty_status'],
        ]);

        return response()->json([
            'run_id' => $runId,
            'status' => 'started',
            'heartbeat_interval_seconds' => 30,
        ]);
    }
}
