<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RegisterLocalWorkspaceController extends Controller
{
    public function __construct(private readonly ProjectLifecycleService $lifecycle)
    {
    }

    public function __invoke(Request $request, string $repository): JsonResponse
    {
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
        ]);

        $now = now();
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
