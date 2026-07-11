<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\GenesisGraphImportService;
use App\Services\Neo4j\FakeNeo4jClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunRetryImportController extends Controller
{
    use ChecksDashboardRoles;

    public function __construct(private readonly GenesisGraphImportService $graphs) {}

    public function __invoke(Request $request, string $run): JsonResponse
    {
        abort_unless($this->canRetryImports($request), 403);

        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow, 404);

        $target = $this->retryTarget($run);
        abort_unless($target !== null, 409, 'Run has no retryable failed import.');

        $mode = config('services.devboard.graph_import_mode', 'neo4j');
        $client = null;

        if ($mode === 'fake') {
            $client = new FakeNeo4jClient;
        }

        $this->graphs->importGraphArtifact(
            $target['snapshot_id'],
            $runRow->repository_id,
            $run,
            $target['artifact_id'],
            $client,
            $mode,
            'Graph import retry validated in fake mode.',
            'Graph import retried into Neo4j.',
        );

        DB::table($target['table'])->where('id', $target['id'])->update([
            'status' => 'active',
            'updated_at' => now(),
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $run,
            'event_type' => 'graph.import_retried',
            'severity' => 'info',
            'message' => 'Graph import retried from dashboard.',
            'payload' => json_encode([
                'target_type' => $target['table'],
                'target_id' => $target['id'],
                'snapshot_id' => $target['snapshot_id'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['retried' => true]);
    }

    /**
     * @return array{id: string, table: string, snapshot_id: string, artifact_id: string}|null
     */
    private function retryTarget(string $runId): ?array
    {
        $genesis = DB::table('genesis_imports')->where('run_id', $runId)->first();
        if ($genesis && $genesis->status === 'failed' && $genesis->snapshot_id) {
            $snapshot = DB::table('snapshots')->where('id', $genesis->snapshot_id)->first();

            if ($snapshot?->graph_snapshot_artifact_id) {
                return [
                    'id' => $genesis->id,
                    'table' => 'genesis_imports',
                    'snapshot_id' => $snapshot->id,
                    'artifact_id' => $snapshot->graph_snapshot_artifact_id,
                ];
            }
        }

        $delta = DB::table('delta_syncs')->where('run_id', $runId)->first();
        if ($delta && $delta->status === 'failed' && $delta->new_snapshot_id) {
            $snapshot = DB::table('snapshots')->where('id', $delta->new_snapshot_id)->first();

            if ($snapshot?->graph_snapshot_artifact_id) {
                return [
                    'id' => $delta->id,
                    'table' => 'delta_syncs',
                    'snapshot_id' => $snapshot->id,
                    'artifact_id' => $snapshot->graph_snapshot_artifact_id,
                ];
            }
        }

        return null;
    }

    private function canRetryImports(Request $request): bool
    {
        $user = $request->user();

        return $this->userHasRole($user, 'Developer') || $this->userHasRole($user, 'Admin');
    }
}
