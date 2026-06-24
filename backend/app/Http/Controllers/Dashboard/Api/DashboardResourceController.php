<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DashboardResourceController extends Controller
{
    use ChecksDashboardRoles;

    public function kanban(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->kanban());
    }

    public function updateTask(Request $request, DashboardApiReader $reader, string $task): JsonResponse
    {
        $this->abortUnlessDashboardMutator($request);

        $validated = $request->validate([
            'column' => ['nullable', 'string', 'in:backlog,ready,in_progress,blocked,review,done'],
        ]);

        if (isset($validated['column'])) {
            $columnId = DB::table('kanban_columns')
                ->where('status_key', $validated['column'])
                ->value('id');
            abort_unless($columnId, 422, 'Unknown task column.');

            DB::table('tasks')->where('id', $task)->update([
                'status_column_id' => $columnId,
                'updated_at' => now(),
            ]);
        }

        return response()->json($reader->task($task));
    }

    public function task(Request $request, DashboardApiReader $reader, string $task): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->task($task));
    }

    public function projects(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->projects());
    }

    public function project(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->project($project));
    }

    public function runs(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->runs());
    }

    public function run(Request $request, DashboardApiReader $reader, string $run): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->run($run));
    }

    public function retryImport(Request $request, DashboardApiReader $reader, string $run): JsonResponse
    {
        abort_unless($this->userHasRole($request->user(), 'Developer') || $this->userHasRole($request->user(), 'Admin'), 403);

        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow, 404);

        $target = DB::table('genesis_imports')->where('run_id', $run)->first()
            ?? DB::table('delta_syncs')->where('run_id', $run)->first();
        abort_unless($target, 409, 'Run has no retryable import.');

        $table = DB::table('genesis_imports')->where('run_id', $run)->exists() ? 'genesis_imports' : 'delta_syncs';

        DB::table($table)->where('id', $target->id)->update([
            'status' => 'active',
            'updated_at' => now(),
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $run,
            'event_type' => 'graph.import_retried',
            'severity' => 'info',
            'message' => 'Graph import retried from dashboard API.',
            'payload' => json_encode([
                'target_type' => $table,
                'target_id' => $target->id,
                'retried_by_user_id' => $request->user()->id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json($reader->run($run));
    }

    public function review(Request $request, DashboardApiReader $reader, string $run): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );

        abort_unless(DB::table('runs')->where('id', $run)->exists(), 404);

        if (! DB::table('run_events')->where('run_id', $run)->where('event_type', 'run.reviewed')->exists()) {
            DB::table('run_events')->insert([
                'id' => (string) Str::ulid(),
                'run_id' => $run,
                'event_type' => 'run.reviewed',
                'severity' => 'info',
                'message' => 'Run reviewed from dashboard API.',
                'payload' => json_encode([
                    'reviewed_by_user_id' => $request->user()->id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }

        return response()->json($reader->run($run));
    }

    public function wiki(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->wiki());
    }

    public function wikiPage(Request $request, DashboardApiReader $reader, string $page): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->wikiPage($page));
    }

    public function graph(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->graph(
            snapshotId: $request->query('snapshot_id') ? (string) $request->query('snapshot_id') : null,
            runId: $request->query('run_id') ? (string) $request->query('run_id') : null,
        ));
    }

    public function artifacts(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->artifacts());
    }

    public function downloadArtifact(Request $request, DashboardApiReader $reader, string $run, string $artifact): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->artifactDownload($run, $artifact));
    }

    private function abortUnlessDashboardReader(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }

    private function abortUnlessDashboardMutator(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }
}
