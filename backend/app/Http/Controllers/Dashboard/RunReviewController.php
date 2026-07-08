<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunReviewController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, string $run): JsonResponse|RedirectResponse
    {
        abort_unless($this->canReviewRuns($request), 403);

        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow, 404);

        $existing = DB::table('run_events')
            ->where('run_id', $run)
            ->where('event_type', 'run.reviewed')
            ->exists();

        if (! $existing) {
            DB::table('run_events')->insert([
                'id' => (string) Str::ulid(),
                'run_id' => $run,
                'event_type' => 'run.reviewed',
                'severity' => 'info',
                'message' => 'Run reviewed from dashboard.',
                'payload' => json_encode([
                    'reviewed_by_user_id' => $request->user()->id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json(['reviewed' => true]);
        }

        return back(303);
    }

    private function canReviewRuns(Request $request): bool
    {
        $user = $request->user();

        return $this->userHasRole($user, 'PM')
            || $this->userHasRole($user, 'Developer')
            || $this->userHasRole($user, 'Sysadmin')
            || $this->userHasRole($user, 'Admin');
    }
}
