<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Plugin\Concerns\HandlesRunResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RunHeartbeatController extends Controller
{
    use HandlesRunResponses;

    public function __invoke(Request $request, string $run): JsonResponse
    {
        $runRow = $this->runOrFail($run);
        if ($error = $this->assertRunActive($runRow)) {
            return $error;
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string'],
        ]);

        DB::table('runs')->where('id', $run)->update([
            'status' => 'heartbeat',
            'updated_at' => now(),
        ]);

        $this->appendRunEvent($run, 'run.heartbeat', 'info', $validated['message'] ?? 'Run heartbeat.');

        return response()->json([
            'run_id' => $run,
            'status' => 'heartbeat',
        ]);
    }
}
