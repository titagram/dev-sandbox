<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Plugin\Concerns\HandlesRunResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RunFinishController extends Controller
{
    use HandlesRunResponses;

    public function __invoke(Request $request, string $run): JsonResponse
    {
        $runRow = $this->runOrFail($run);
        if ($error = $this->assertRunActive($runRow)) {
            return $error;
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:finished,failed,aborted'],
            'summary' => ['nullable', 'string'],
            'risk_report' => ['nullable', 'array'],
            'risk_report.risk_level' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'risk_report.summary' => ['nullable', 'string'],
            'risk_report.triggers' => ['nullable', 'array'],
            'risk_report.triggers.*' => ['string'],
        ]);

        $riskLevel = $validated['risk_report']['risk_level'] ?? $runRow->risk_level;
        $now = now();

        DB::table('runs')->where('id', $run)->update([
            'status' => $validated['status'],
            'summary' => $validated['summary'] ?? null,
            'risk_level' => $riskLevel,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);

        $this->appendRunEvent($run, 'run.finished', $validated['status'] === 'failed' ? 'error' : 'info', 'Run finished.', [
            'status' => $validated['status'],
            'summary' => $validated['summary'] ?? null,
            'risk_report' => $validated['risk_report'] ?? null,
        ]);

        return response()->json([
            'run_id' => $run,
            'status' => $validated['status'],
        ]);
    }
}
