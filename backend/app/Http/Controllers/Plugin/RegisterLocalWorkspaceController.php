<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\PluginProjectScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RegisterLocalWorkspaceController extends Controller
{
    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginProjectScope $projectScope,
    ) {}

    public function __invoke(Request $request, string $repository): JsonResponse
    {
        $repositoryRow = DB::table('repositories')->where('id', $repository)->first();
        abort_unless($repositoryRow, Response::HTTP_NOT_FOUND);
        if ($error = $this->projectScope->authorize($request, (string) $repositoryRow->project_id)) {
            return $error;
        }

        if ($error = $this->lifecycle->pluginRepositoryWriteGuard($repository)) {
            return $error;
        }

        $auth = $request->attributes->get('plugin_auth');
        $device = $auth['device'];

        if (! $device || $request->header('X-DevBoard-Device-Id') !== $device->id) {
            return response()->json([
                'error' => [
                    'code' => 'device_required',
                    'message' => 'A registered plugin device is required.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validate([
            'local_root_hash' => ['required', 'string', 'max:255'],
            'display_path' => ['required', 'string', 'max:1024'],
            'current_branch' => ['required', 'string', 'max:255'],
            'last_head_sha' => ['nullable', 'string', 'max:255'],
            'dirty_status' => ['required', 'string', 'max:64'],
            'remote_name' => ['nullable', 'string', 'max:255'],
            'remote_url_host' => ['nullable', 'string', 'max:255'],
            'remote_url_hash' => ['nullable', 'string', 'max:255', 'regex:/^sha256:[a-f0-9]{64}$/'],
            'upstream_branch' => ['nullable', 'string', 'max:255'],
            'ahead_count' => ['nullable', 'integer', 'min:0'],
            'behind_count' => ['nullable', 'integer', 'min:0'],
            'git_state_observed_at' => ['nullable', 'date'],
        ]);

        $now = now();
        $gitState = [
            'remote_name' => $validated['remote_name'] ?? null,
            'remote_url_host' => $validated['remote_url_host'] ?? null,
            'remote_url_hash' => $validated['remote_url_hash'] ?? null,
            'upstream_branch' => $validated['upstream_branch'] ?? null,
            'ahead_count' => $validated['ahead_count'] ?? null,
            'behind_count' => $validated['behind_count'] ?? null,
            'git_state_observed_at' => $validated['git_state_observed_at'] ?? null,
        ];

        $workspace = DB::table('local_workspaces')
            ->where('repository_id', $repository)
            ->where('device_id', $device->id)
            ->where('local_root_hash', $validated['local_root_hash'])
            ->first();

        if ($workspace) {
            DB::table('local_workspaces')->where('id', $workspace->id)->update([
                'display_path' => $validated['display_path'],
                'current_branch' => $validated['current_branch'],
                'last_head_sha' => $validated['last_head_sha'] ?? null,
                'dirty_status' => $validated['dirty_status'],
                ...$gitState,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);

            $workspaceId = $workspace->id;
        } else {
            $workspaceId = (string) Str::ulid();

            DB::table('local_workspaces')->insert([
                'id' => $workspaceId,
                'repository_id' => $repository,
                'device_id' => $device->id,
                'local_root_hash' => $validated['local_root_hash'],
                'display_path' => $validated['display_path'],
                'current_branch' => $validated['current_branch'],
                'last_head_sha' => $validated['last_head_sha'] ?? null,
                'dirty_status' => $validated['dirty_status'],
                ...$gitState,
                'last_snapshot_id' => null,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return response()->json([
            'protocol_version' => 'v1',
            'local_workspace_id' => $workspaceId,
            'repository_id' => $repository,
            'device_id' => $device->id,
            'status' => 'linked',
        ]);
    }
}
