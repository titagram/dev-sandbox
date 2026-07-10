<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\PluginInvariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeltaLocalSnapshotController extends Controller
{
    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginInvariantService $invariants,
    ) {}

    public function __invoke(Request $request, string $run): JsonResponse
    {
        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow, 404);

        if ($error = $this->lifecycle->pluginRunWriteGuard($run)) {
            return $error;
        }

        if ($error = $this->invariants->assertRunOwnership($request, $runRow)) {
            return $error;
        }

        $validated = $request->validate([
            'local_workspace_id' => ['required', 'string', 'exists:local_workspaces,id'],
            'branch' => ['required', 'string', 'max:255'],
            'head_sha' => ['nullable', 'string', 'max:255'],
            'dirty_status' => ['required', 'string', 'max:64'],
            'changed_files' => ['nullable', 'array'],
        ]);

        $workspace = DB::table('local_workspaces')->where('id', $validated['local_workspace_id'])->first();
        if ($error = $this->invariants->assertReferences(
            (string) $runRow->local_workspace_id === (string) $workspace->id
                && (string) $runRow->repository_id === (string) $workspace->repository_id
                && (string) $runRow->device_id === (string) $workspace->device_id,
            'Local snapshot workspace, repository, run, and device references are inconsistent.',
        )) {
            return $error;
        }

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
