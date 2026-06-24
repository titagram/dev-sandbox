<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeltaLocalSnapshotController extends Controller
{
    public function __construct(private readonly ProjectLifecycleService $lifecycle)
    {
    }

    public function __invoke(Request $request, string $run): JsonResponse
    {
        if ($error = $this->lifecycle->pluginRunWriteGuard($run)) {
            return $error;
        }

        $validated = $request->validate([
            'local_workspace_id' => ['required', 'string', 'exists:local_workspaces,id'],
            'branch' => ['required', 'string', 'max:255'],
            'head_sha' => ['nullable', 'string', 'max:255'],
            'dirty_status' => ['required', 'string', 'max:64'],
            'changed_files' => ['nullable', 'array'],
        ]);

        DB::table('local_workspaces')->where('id', $validated['local_workspace_id'])->update([
            'current_branch' => $validated['branch'],
            'last_head_sha' => $validated['head_sha'] ?? null,
            'dirty_status' => $validated['dirty_status'],
            'last_seen_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $run,
            'event_type' => 'local_snapshot.received',
            'severity' => 'info',
            'message' => 'Local plugin snapshot metadata received.',
            'payload' => json_encode([
                'local_workspace_id' => $validated['local_workspace_id'],
                'branch' => $validated['branch'],
                'head_sha' => $validated['head_sha'] ?? null,
                'dirty_status' => $validated['dirty_status'],
                'changed_file_count' => count($validated['changed_files'] ?? []),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json([
            'run_id' => $run,
            'status' => 'local_snapshot_received',
        ]);
    }
}
