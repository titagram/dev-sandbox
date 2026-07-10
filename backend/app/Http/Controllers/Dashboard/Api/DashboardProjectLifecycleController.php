<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardProjectLifecycleController extends Controller
{
    use ChecksDashboardRoles;

    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly DashboardApiReader $reader,
    ) {}

    public function archive(Request $request, string $project): JsonResponse
    {
        return $this->transition($request, $project, 'archive');
    }

    public function restore(Request $request, string $project): JsonResponse
    {
        return $this->transition($request, $project, 'restore');
    }

    public function delete(Request $request, string $project): JsonResponse
    {
        return $this->transition($request, $project, 'delete');
    }

    private function transition(Request $request, string $project, string $action): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin') || $this->userHasRole($request->user(), 'PM'),
            403,
        );

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->lifecycle->transition(
            projectId: $project,
            action: $action,
            actor: $request->user(),
            reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
            request: $request,
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json($this->reader->projectLifecycle($project));
    }
}
