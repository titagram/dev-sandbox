<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Plugin\Concerns\HandlesRunResponses;
use App\Projects\ProjectLifecycleService;
use App\Services\PluginInvariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RunEventController extends Controller
{
    use HandlesRunResponses;

    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginInvariantService $invariants,
    ) {}

    public function __invoke(Request $request, string $run): JsonResponse
    {
        $runRow = $this->runOrFail($run);
        if ($error = $this->lifecycle->pluginRunWriteGuard($run)) {
            return $error;
        }

        if ($error = $this->invariants->assertRunOwnership($request, $runRow)) {
            return $error;
        }

        if ($error = $this->assertRunActive($runRow)) {
            return $error;
        }

        $validated = $request->validate([
            'event_type' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'string', 'in:info,warning,error,critical'],
            'message' => ['required', 'string'],
            'payload' => ['nullable', 'array'],
        ]);

        $this->appendRunEvent(
            $run,
            $validated['event_type'],
            $validated['severity'],
            $validated['message'],
            $validated['payload'] ?? [],
        );

        if ($validated['event_type'] === 'artifact_uploaded') {
            DB::table('runs')->where('id', $run)->update([
                'status' => 'artifact_uploaded',
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'run_id' => $run,
            'status' => 'recorded',
        ]);
    }
}
